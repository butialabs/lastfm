<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\LastFmService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class SettingsForm extends Component
{
    public string $lastfm_username = '';

    public int $day_of_week = 7;

    public string $hour = '09:00';

    public string $timezone = 'UTC';

    public bool $editing = false;

    public ?string $flashMessage = null;

    /** @var list<string> */
    public array $timezones = [];

    public function mount(): void
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        $this->lastfm_username = (string) ($user->lastfm_username ?? '');
        $this->day_of_week = (int) ($user->day_of_week ?? 7);
        $this->timezone = (string) ($user->timezone ?? 'UTC');
        $this->hour = $user->scheduleHourForTimezone($this->timezone);
        $this->timezones = \DateTimeZone::listIdentifiers();

        // Incomplete setup → start in editing mode.
        $this->editing = ! ($user->lastfm_username && $user->day_of_week && $user->time && $user->timezone);

        if (session()->has('flash')) {
            $this->flashMessage = (string) session('flash');
        }
    }

    public function edit(): void
    {
        $this->editing = true;
        $this->flashMessage = null;
    }

    public function save(LastFmService $lastfm)
    {
        $this->validate([
            'lastfm_username' => ['required', 'string', 'max:255'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'hour' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'string', \Illuminate\Validation\Rule::in(\DateTimeZone::listIdentifiers())],
        ], [
            'lastfm_username.required' => __('messages.error.missing_fields'),
            'hour.date_format' => __('messages.error.invalid_time'),
            'timezone.in' => __('messages.error.invalid_timezone'),
        ]);

        try {
            if (! $lastfm->validateUser($this->lastfm_username)) {
                $this->addError('lastfm_username', __('messages.error.lastfm_user_not_found'));

                return null;
            }

            [$utcDow, $utcTime] = User::convertLocalScheduleToUtc(
                dayOfWeek: $this->day_of_week,
                hour: $this->hour,
                timezone: new \DateTimeZone($this->timezone),
            );

            $user = Auth::user();
            $user->fill([
                'lastfm_username' => $this->lastfm_username,
                'day_of_week' => $utcDow,
                'time' => $utcTime,
                'timezone' => $this->timezone,
                'status' => User::STATUS_SCHEDULE,
                'error_count' => 0,
            ])->save();
        } catch (\Throwable $e) {
            Log::channel('app')->error('Saving settings failed', ['error' => $e->getMessage()]);
            $this->addError('save', __('messages.error.generic'));

            return null;
        }

        $this->flashMessage = __('messages.settings.saved');
        $this->editing = false;

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

    public function getStatusTextProperty(): string
    {
        $user = Auth::user();

        $key = match ((string) ($user?->status ?? User::STATUS_ACTIVE)) {
            User::STATUS_SCHEDULE => 'messages.status.schedule',
            User::STATUS_QUEUED => 'messages.status.queued',
            User::STATUS_SENDING => 'messages.status.sending',
            User::STATUS_ERROR => 'messages.status.error',
            default => 'messages.status.active',
        };

        return __($key);
    }

    public function render()
    {
        return view('livewire.settings-form', [
            'user' => Auth::user(),
        ]);
    }
}
