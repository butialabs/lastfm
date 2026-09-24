<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SCHEDULE = 'SCHEDULE';

    public const STATUS_QUEUED = 'QUEUED';

    public const STATUS_SENDING = 'SENDING';

    public const STATUS_ERROR = 'ERROR';

    public const PROTOCOL_AT = 'at';

    public const PROTOCOL_MASTODON = 'mastodon';

    protected $fillable = [
        'protocol',
        'instance',
        'username',
        'did',
        'password',
        'token',
        'lastfm_username',
        'day_of_week',
        'time',
        'timezone',
        'language',
        'status',
        'callback',
        'social_message',
        'social_montage',
        'error_count',
        'send_attempts',
    ];

    protected $hidden = [
        'password',
        'token',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'error_count' => 'integer',
            'send_attempts' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return HasMany<ArtistStat, $this> */
    public function artistStats(): HasMany
    {
        return $this->hasMany(ArtistStat::class);
    }

    public static function upsertAtUser(string $instance, string $username, string $did, string $encryptedPassword, string $preferredLanguage): self
    {
        $user = static::firstOrNew([
            'protocol' => self::PROTOCOL_AT,
            'instance' => $instance,
            'username' => $username,
        ]);

        if (! $user->exists) {
            $user->status = self::STATUS_ACTIVE;
        }

        $user->fill([
            'did' => $did,
            'password' => $encryptedPassword,
            'language' => $preferredLanguage,
        ])->save();

        return $user;
    }

    public static function upsertMastodonUser(string $instance, string $username, string $encryptedToken, string $preferredLanguage): self
    {
        $user = static::firstOrNew([
            'protocol' => self::PROTOCOL_MASTODON,
            'instance' => $instance,
            'username' => $username,
        ]);

        if (! $user->exists) {
            $user->status = self::STATUS_ACTIVE;
        }

        $user->fill([
            'token' => $encryptedToken,
            'language' => $preferredLanguage,
        ])->save();

        return $user;
    }

    /*
     * Send state machine: ACTIVE → SCHEDULE → QUEUED → SENDING → SCHEDULE
     * send_attempts drives the retries within a week; error_count counts
     * consecutive failed weeks and moves the user to ERROR at the limit.
     */

    public function markQueued(string $montagePath, bool $resetAttempts = false): void
    {
        $this->forceFill([
            'status' => self::STATUS_QUEUED,
            'social_montage' => $montagePath,
            ...($resetAttempts ? ['send_attempts' => 0] : []),
        ])->save();
    }

    public function markSending(): void
    {
        $this->forceFill(['status' => self::STATUS_SENDING])->save();
    }

    public function markScheduledAfterSend(string $socialMessage): void
    {
        $this->forceFill([
            'status' => self::STATUS_SCHEDULE,
            'social_message' => $socialMessage,
            'error_count' => 0,
            'send_attempts' => 0,
        ])->save();
    }

    // A failed send attempt within the week: stays QUEUED for the next tick.
    public function registerSendAttemptFailure(string $message): int
    {
        $this->forceFill([
            'status' => self::STATUS_QUEUED,
            'callback' => $message,
            'send_attempts' => ((int) $this->send_attempts) + 1,
        ])->save();

        return (int) $this->send_attempts;
    }

    // The whole week failed: back to SCHEDULE, or ERROR after too many weeks in a row.
    public function registerWeeklyFailure(string $message): int
    {
        $errorCount = ((int) $this->error_count) + 1;
        $disabled = $errorCount >= (int) config('lastfm.max_error_count', 3);

        $this->forceFill([
            'status' => $disabled ? self::STATUS_ERROR : self::STATUS_SCHEDULE,
            'callback' => $disabled
                ? "Disabled after {$errorCount} consecutive failed weeks: {$message}"
                : $message,
            'error_count' => $errorCount,
            'send_attempts' => 0,
        ])->save();

        return $errorCount;
    }

    public function setCallback(string $message): void
    {
        $this->forceFill(['callback' => $message])->save();
    }

    // On panel access: reset error count and re-enable ERROR users.
    public function resetOnAccess(): void
    {
        $newStatus = (filled($this->lastfm_username) && $this->status === self::STATUS_ERROR)
            ? self::STATUS_SCHEDULE
            : $this->status;

        if ((int) $this->error_count === 0 && (int) $this->send_attempts === 0 && $newStatus === $this->status) {
            return;
        }

        $this->forceFill(['error_count' => 0, 'send_attempts' => 0, 'status' => $newStatus])->save();
    }

    /**
     * Users scheduled for the current minute. Comparison happens in UTC,
     * matching the legacy behavior (day/time are stored in UTC). `time` is a
     * string column, so the minute is matched by prefix ("HH:MM" or "HH:MM:SS").
     *
     * @return Collection<int, User>
     */
    public static function dueForSchedule(DateTimeInterface $nowUtc): Collection
    {
        $now = CarbonImmutable::instance($nowUtc)->setTimezone('UTC');

        return static::query()
            ->where('status', self::STATUS_SCHEDULE)
            ->where('day_of_week', (int) $now->format('N'))
            ->where('time', 'LIKE', $now->format('H:i').'%')
            ->whereNotNull('timezone')
            ->whereNotNull('lastfm_username')
            ->where('lastfm_username', '!=', '')
            ->get();
    }

    /** @return Collection<int, User> */
    public static function queued(): Collection
    {
        return static::query()->where('status', self::STATUS_QUEUED)->get();
    }

    public static function countActive(): int
    {
        return static::query()
            ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_SCHEDULE])
            ->count();
    }

    /**
     * Convert a local schedule (day 1-7 + "HH:MM" + timezone) to UTC.
     *
     * @return array{0:int,1:string} [dayOfWeekUtc (N, 1=Mon..7=Sun), 'H:i:s']
     */
    public static function convertLocalScheduleToUtc(int $dayOfWeek, string $hour, DateTimeZone $timezone): array
    {
        $nowLocal = CarbonImmutable::now($timezone);

        $daysAhead = ($dayOfWeek - (int) $nowLocal->format('N') + 7) % 7;
        $targetDate = $nowLocal->addDays($daysAhead)->startOfDay();

        [$hh, $mm] = array_map('intval', array_pad(explode(':', $hour), 2, 0));
        $targetUtc = $targetDate->setTime($hh, $mm)->setTimezone('UTC');

        return [(int) $targetUtc->format('N'), $targetUtc->format('H:i:s')];
    }

    public function scheduleHourForTimezone(string $timezone): string
    {
        $dow = (int) ($this->day_of_week ?? 7);
        $time = (string) ($this->time ?? '09:00:00');

        try {
            $tz = new DateTimeZone($timezone);
        } catch (\Throwable) {
            $tz = new DateTimeZone('UTC');
        }

        $nowUtc = CarbonImmutable::now('UTC');
        $daysAhead = ($dow - (int) $nowUtc->format('N') + 7) % 7;

        [$hh, $mm, $ss] = array_map('intval', array_pad(explode(':', $time), 3, 0));

        return $nowUtc->addDays($daysAhead)
            ->setTime($hh, $mm, $ss)
            ->setTimezone($tz)
            ->format('H:i');
    }

    // Montage URLs are /montage/{md5(user_id)} — preserved from the legacy app.
    public static function montageUrlToFilePath(string $montageUrl): ?string
    {
        if (preg_match('#^/montage/([a-f0-9]{32})$#i', $montageUrl, $matches)) {
            return Storage::disk('montage')->path($matches[1].'.jpg');
        }

        return null;
    }

    public function deleteMontageFile(?string $montageUrl): void
    {
        $path = $montageUrl !== null ? static::montageUrlToFilePath($montageUrl) : null;

        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}
