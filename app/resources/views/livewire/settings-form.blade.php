<div class="admin box">
	<div class="configure">
		<div>Configure:</div>
		<div>
			<p>
				@if ($user->protocol === 'at')
					<a href="https://bsky.app/profile/{{ $user->username }}" target="_blank">{{ '@'.$user->username }}</a>
				@endif
				@if ($user->protocol === 'mastodon')
					<a href="{{ $user->instance.'/@'.$user->username }}" target="_blank">{{ '@'.$user->username }}</a>
				@endif
			</p>
			<form method="post" action="{{ route('logout') }}">
				@csrf
				<button type="submit">{{ __('messages.settings.logout') }}</button>
			</form>
		</div>
	</div>

	@if ($flashMessage)
		<div class="alert alert--info">
			{{ $flashMessage }}
		</div>
	@endif

	@if (! $editing)
		<div class="edit">
			<div>
				{{ __('messages.settings.lastfm_username') }}
				<p>{{ $user->lastfm_username }}</p>
			</div>
			<div>
				{{ __('messages.settings.day_of_week') }}
				<p>
					{{ __(match ((int) $user->day_of_week) {
						1 => 'messages.day.monday',
						2 => 'messages.day.tuesday',
						3 => 'messages.day.wednesday',
						4 => 'messages.day.thursday',
						5 => 'messages.day.friday',
						6 => 'messages.day.saturday',
						default => 'messages.day.sunday',
					}) }}
				</p>
			</div>
			<div>
				{{ __('messages.settings.hour') }}
				<p>
					{{ $hour }}
					({{ $user->timezone ?? 'UTC' }})
				</p>
			</div>
			<div>
				<button type="button" id="edit" wire:click="edit">{{ __('messages.settings.edit') }}</button>
			</div>
		</div>
	@endif

	<div class="save" style="display: {{ $editing ? 'flex' : 'none' }}">
		<form wire:submit="save" autocomplete="off" data-form-type="other">
			<div class="form-fill">
				<div class="form-row">
					<input type="text" id="lastfm_username" wire:model="lastfm_username" placeholder="{{ __('messages.settings.lastfm_username') }}" required>
					<select id="day_of_week" wire:model="day_of_week" required>
						<option value="7">{{ __('messages.day.sunday') }}</option>
						<option value="1">{{ __('messages.day.monday') }}</option>
						<option value="2">{{ __('messages.day.tuesday') }}</option>
						<option value="3">{{ __('messages.day.wednesday') }}</option>
						<option value="4">{{ __('messages.day.thursday') }}</option>
						<option value="5">{{ __('messages.day.friday') }}</option>
						<option value="6">{{ __('messages.day.saturday') }}</option>
					</select>
				</div>
				<div class="form-row">
					<input type="time" id="hour" wire:model="hour" required>
					<select wire:model="timezone" id="timezone" required>
						@foreach ($timezones as $tz)
							<option value="{{ $tz }}" @selected($tz === $timezone)>{{ $tz }}</option>
						@endforeach
					</select>
				</div>
			</div>
			@error('lastfm_username')
				<div class="alert alert--danger">{{ $message }}</div>
			@enderror
			@error('hour')
				<div class="alert alert--danger">{{ $message }}</div>
			@enderror
			@error('timezone')
				<div class="alert alert--danger">{{ $message }}</div>
			@enderror
			@error('save')
				<div class="alert alert--danger">{{ $message }}</div>
			@enderror
			<button type="submit" id="save_button" wire:loading.attr="disabled" wire:target="save">
				<span wire:loading.remove wire:target="save">{{ __('messages.settings.save') }}</span>
				<span wire:loading wire:target="save">...</span>
			</button>
		</form>
	</div>

	@if ($user->social_message)
		<div class="social_message alert alert--secondary">
			{{ __('messages.settings.last_update') }}: <strong>{{ $user->social_message }}</strong>
			@if ($user->social_montage)
				{{ __('messages.settings.with_montage') }} <strong><a href="{{ $user->social_montage }}" target="_blank">{{ __('messages.settings.montage') }}</a></strong>
			@endif
		</div>
	@endif

	<div class="alert alert--after alert--info">
		<strong>{{ $this->statusText }}</strong>
	</div>

	<div class="delete">
		<button type="button" id="delete_button" wire:click="deleteAccount" wire:confirm="{{ __('messages.settings.confirm_delete') }}">
			{{ __('messages.settings.remove_account') }}
		</button>
	</div>
</div>
