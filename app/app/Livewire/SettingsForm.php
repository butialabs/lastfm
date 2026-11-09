<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\LastFm\LastFmApi;
use App\Services\MontageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Component;

class SettingsForm extends Component
{
    public string $lastfm_username = '';

    public int $day_of_week = 7;

    public string $hour = '09:00';

    public string $timezone = 'UTC';

    public string $grid_source = User::GRID_ARTIST;

    public int $grid_size = MontageService::DEFAULT_SIZE;

    public bool $editing = false;

    /** True until the schedule has been saved once (first run). */
    public bool $firstRun = false;

    public ?string $flashMessage = null;

    public function mount(): void
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        $this->fillFromUser($user);

        // Incomplete setup → start in editing mode.
        $this->firstRun = ! ($user->lastfm_username && $user->day_of_week && $user->time && $user->timezone);
        $this->editing = $this->firstRun;

        if (session()->has('flash')) {
            $this->flashMessage = (string) session('flash');
        }
    }

    public function edit(): void
    {
        $this->editing = true;
        $this->flashMessage = null;
    }

    /** Leave edit mode without saving: restore what is stored. */
    public function cancel(): void
    {
        if ($this->firstRun) {
            return;
        }

        $this->fillFromUser(Auth::user());
        $this->resetValidation();
        $this->editing = false;
    }

    public function save(LastFmApi $lastfm)
    {
        $this->lastfm_username = trim($this->lastfm_username);

        $this->validate([
            'lastfm_username' => ['required', 'string', 'max:255'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'hour' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'grid_source' => ['required', 'string', Rule::in(User::GRID_SOURCES)],
            'grid_size' => ['required', 'integer', Rule::in(MontageService::SIZES)],
        ], [
            'lastfm_username.required' => __('messages.error.lastfm_username_required'),
            'day_of_week.*' => __('messages.error.invalid_day'),
            'hour.required' => __('messages.error.invalid_time'),
            'hour.date_format' => __('messages.error.invalid_time'),
            'timezone.*' => __('messages.error.invalid_timezone'),
            'grid_source.*' => __('messages.error.invalid_grid'),
            'grid_size.*' => __('messages.error.invalid_grid'),
        ]);

        try {
            $exists = $lastfm->userExists($this->lastfm_username);
        } catch (\Throwable $e) {
            Log::channel('app')->warning('Last.fm user check failed', ['error' => $e->getMessage()]);
            $this->addError('lastfm_username', __('messages.error.lastfm_unreachable'));

            return null;
        }

        if (! $exists) {
            $this->addError('lastfm_username', sprintf(__('messages.error.lastfm_user_not_found'), $this->lastfm_username));

            return null;
        }

        try {
            [$utcDow, $utcTime] = User::convertLocalScheduleToUtc(
                dayOfWeek: $this->day_of_week,
                hour: $this->hour,
                timezone: new \DateTimeZone($this->timezone),
            );

            Auth::user()->fill([
                'lastfm_username' => $this->lastfm_username,
                'day_of_week' => $utcDow,
                'time' => $utcTime,
                'timezone' => $this->timezone,
                'grid_source' => $this->grid_source,
                'grid_size' => (int) $this->grid_size,
                'status' => User::STATUS_SCHEDULE,
                'error_count' => 0,
                'send_attempts' => 0,
            ])->save();
        } catch (\Throwable $e) {
            Log::channel('app')->error('Saving settings failed', ['error' => $e->getMessage()]);
            $this->addError('save', __('messages.error.save_failed'));

            return null;
        }

        $this->flashMessage = __('messages.settings.saved');
        $this->editing = false;
        $this->firstRun = false;

        return null;
    }

    public function deleteAccount()
    {
        $user = Auth::user();

        if ($user !== null) {
            $user->deleteMontageFile($user->social_montage);
            $user->delete();
        }

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return $this->redirect('/');
    }

    /**
     * Status shown at the top of the card: tone (success|info|danger|neutral) + message.
     *
     * @return array{tone:string, message:string}
     */
    public function getStatusProperty(): array
    {
        $user = Auth::user();
        $next = $user?->nextPostAt();

        return match ((string) ($user?->status ?? User::STATUS_ACTIVE)) {
            User::STATUS_SCHEDULE => $next
                ? ['tone' => 'success', 'message' => sprintf(__('messages.status.schedule'), $this->formatWhen($next), $next->locale($this->carbonLocale())->diffForHumans())]
                : ['tone' => 'neutral', 'message' => __('messages.status.active')],
            User::STATUS_QUEUED => ['tone' => 'info', 'message' => __('messages.status.queued')],
            User::STATUS_SENDING => ['tone' => 'info', 'message' => __('messages.status.sending')],
            User::STATUS_ERROR => ['tone' => 'danger', 'message' => __('messages.status.error')],
            default => ['tone' => 'neutral', 'message' => __('messages.status.active')],
        };
    }

    public function render()
    {
        $user = Auth::user();
        $next = $user->nextPostAt();

        return view('livewire.settings-form', [
            'user' => $user,
            'gridSizes' => MontageService::SIZES,
            'timezoneGroups' => $this->timezoneGroups(),
            'scheduleSummary' => $next ? $this->formatSchedule($next) : null,
        ]);
    }

    private function fillFromUser(User $user): void
    {
        $this->lastfm_username = (string) ($user->lastfm_username ?? '');
        $this->timezone = (string) ($user->timezone ?? 'UTC');
        $this->grid_source = $user->gridSource();
        $this->grid_size = $user->gridSize();

        // Stored day/time are UTC: show them in the user's own timezone.
        $next = $user->nextPostAt();
        $this->day_of_week = $next ? (int) $next->format('N') : 5;
        $this->hour = $next ? $next->format('H:i') : '09:00';
    }

    /** "sexta-feira às 09:00" style summary of the weekly slot. */
    private function formatSchedule(CarbonImmutable $next): string
    {
        $local = $next->locale($this->carbonLocale());

        return sprintf(__('messages.settings.schedule_summary'), $local->isoFormat('dddd'), $local->isoFormat('LT'), self::timezoneLabel($next->getTimezone()->getName()));
    }

    private function formatWhen(CarbonImmutable $next): string
    {
        return $next->locale($this->carbonLocale())->isoFormat('dddd, LL, LT');
    }

    private function carbonLocale(): string
    {
        return str_replace('-', '_', app()->getLocale());
    }

    /**
     * Timezones grouped by region, labelled "City (UTC−03:00)".
     *
     * @return array<string, array<string, string>>
     */
    private function timezoneGroups(): array
    {
        $groups = [];
        foreach (\DateTimeZone::listIdentifiers() as $tz) {
            $region = str_contains($tz, '/') ? strstr($tz, '/', true) : 'UTC';
            $groups[$region][$tz] = self::timezoneLabel($tz);
        }

        return $groups;
    }

    public static function timezoneLabel(string $tz): string
    {
        try {
            $offset = (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('P');
        } catch (\Throwable) {
            return $tz;
        }

        $city = str_replace('_', ' ', str_contains($tz, '/') ? substr($tz, strrpos($tz, '/') + 1) : $tz);

        return sprintf('%s (UTC%s)', $city, str_replace('-', '−', $offset));
    }
}
