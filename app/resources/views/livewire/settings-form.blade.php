@php
    $newTab = '<span class="visually-hidden"> ('.e(__('messages.login.new_tab')).')</span>';
    $profileUrl = $user->protocol === 'mastodon'
        ? rtrim((string) $user->instance, '/').'/@'.$user->username
        : 'https://bsky.app/profile/'.$user->username;
    $days = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
    $status = $this->status;
    // aria-describedby for a field: its hint (if any) + its error (if any).
    $describedBy = fn (string $field, ?string $hint = null) => trim(($hint ?? '').' '.($errors->has($field) ? $field.'-error' : '')) ?: null;
    $gridSource = $editing ? $grid_source : $user->gridSource();
    $gridSize = $editing ? $grid_size : $user->gridSize();
@endphp
<div class="settings box">

    <div class="account">
        <span class="account__icon account__icon--{{ $user->protocol === 'mastodon' ? 'mastodon' : 'bluesky' }}" aria-hidden="true"></span>
        <p class="account__who">
            <span class="account__label">{{ __('messages.settings.connected_as') }}</span>
            <a href="{{ $profileUrl }}" target="_blank" rel="noopener">{{ '@'.$user->username }}{!! $newTab !!}</a>
        </p>
        <form method="post" action="{{ route('logout') }}" class="account__logout">
            @csrf
            <button type="submit" class="link-button">{{ __('messages.settings.logout') }}</button>
        </form>
    </div>

    <div
        class="status status--{{ $status['tone'] }}"
        role="status"
        tabindex="-1"
        @if ($flashMessage) wire:key="status-{{ md5($flashMessage.microtime()) }}" x-init="$el.focus({ preventScroll: true })" @endif
    >
        @if ($flashMessage)<strong>{{ $flashMessage }}</strong> @endif{{ $status['message'] }}
    </div>

    <h2 class="step__title">{{ __('messages.settings.title') }}</h2>

    @if (! $editing)
        <div class="summary">
            <div class="summary__preview">
                <div class="mosaic" data-size="{{ $gridSize }}" aria-hidden="true">
                    @for ($i = 1; $i <= 9; $i++)<span>{{ $i }}</span>@endfor
                </div>
            </div>
            <dl class="summary__list">
                <div>
                    <dt>{{ __('messages.settings.lastfm_username') }}</dt>
                    <dd><a href="https://www.last.fm/user/{{ rawurlencode((string) $user->lastfm_username) }}" target="_blank" rel="noopener">{{ $user->lastfm_username }}{!! $newTab !!}</a></dd>
                </div>
                <div>
                    <dt>{{ __('messages.settings.when_label') }}</dt>
                    <dd>{{ $scheduleSummary }}</dd>
                </div>
                <div>
                    <dt>{{ __('messages.settings.images_label') }}</dt>
                    <dd>{{ __($gridSource === 'album' ? 'messages.settings.grid_album' : 'messages.settings.grid_artist') }} · {{ __('messages.settings.grid_layout_'.$gridSize) }}</dd>
                </div>
            </dl>
            <button type="button" class="btn-secondary" id="edit" wire:click="edit">{{ __('messages.settings.edit') }}</button>
        </div>
    @else
        <form wire:submit="save" novalidate autocomplete="off" data-form-type="other">
            @if ($errors->any())
                {{-- New key on every failed submit, so focus always lands on the first invalid field. --}}
                <span hidden wire:key="focus-{{ md5(json_encode($errors->keys()).microtime()) }}" x-init="$nextTick(() => document.querySelector('.settings [aria-invalid=true]')?.focus())"></span>
            @endif

            <div class="field">
                <label for="lastfm_username">{{ __('messages.settings.lastfm_username') }}</label>
                <p class="field__hint" id="lastfm_username-hint">{{ __('messages.settings.lastfm_hint') }}</p>
                <input
                    type="text"
                    id="lastfm_username"
                    wire:model="lastfm_username"
                    autocapitalize="none"
                    autocorrect="off"
                    spellcheck="false"
                    aria-describedby="{{ $describedBy('lastfm_username', 'lastfm_username-hint') }}"
                    @error('lastfm_username') aria-invalid="true" @enderror
                    required
                >
                @error('lastfm_username')<p class="field__error" id="lastfm_username-error">{{ $message }}</p>@enderror
            </div>

            <fieldset class="group">
                <legend>{{ __('messages.settings.when') }}</legend>
                <div class="group__row group__row--when">
                    <div class="field">
                        <label for="day_of_week">{{ __('messages.settings.day_of_week') }}</label>
                        <select id="day_of_week" wire:model="day_of_week" @if ($describedBy('day_of_week')) aria-describedby="{{ $describedBy('day_of_week') }}" @endif @error('day_of_week') aria-invalid="true" @enderror>
                            @foreach ($days as $n => $day)
                                <option value="{{ $n }}">{{ __('messages.day.'.$day) }}</option>
                            @endforeach
                        </select>
                        @error('day_of_week')<p class="field__error" id="day_of_week-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="hour">{{ __('messages.settings.hour') }}</label>
                        <input type="time" id="hour" wire:model="hour" @if ($describedBy('hour')) aria-describedby="{{ $describedBy('hour') }}" @endif @error('hour') aria-invalid="true" @enderror required>
                        @error('hour')<p class="field__error" id="hour-error">{{ $message }}</p>@enderror
                    </div>
                    <div
                        class="field"
                        x-data="{ detected: false }"
                        @if ($firstRun && ! $user->timezone)
                            x-init="
                                const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                                if (tz && $refs.tz.querySelector(`option[value='${tz}']`)) { $refs.tz.value = tz; $wire.timezone = tz; detected = true; }
                            "
                        @endif
                    >
                        <label for="timezone">{{ __('messages.settings.timezone') }}</label>
                        <select id="timezone" x-ref="tz" wire:model="timezone" aria-describedby="{{ $describedBy('timezone', 'timezone-hint') }}" @error('timezone') aria-invalid="true" @enderror>
                            @foreach ($timezoneGroups as $region => $zones)
                                <optgroup label="{{ $region }}">
                                    @foreach ($zones as $tz => $label)
                                        <option value="{{ $tz }}">{{ $label }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <p class="field__hint field__hint--after" id="timezone-hint" x-show="detected" style="display:none">{{ __('messages.settings.timezone_detected') }}</p>
                        @error('timezone')<p class="field__error" id="timezone-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </fieldset>

            <fieldset class="group">
                <legend>{{ __('messages.settings.appearance') }}</legend>
                <div class="group__row group__row--grid">
                    <div class="group__fields">
                        <div class="field">
                            <label for="grid_source">{{ __('messages.settings.grid_source') }}</label>
                            <select id="grid_source" wire:model="grid_source" @if ($describedBy('grid_source')) aria-describedby="{{ $describedBy('grid_source') }}" @endif @error('grid_source') aria-invalid="true" @enderror>
                                <option value="artist">{{ __('messages.settings.grid_artist') }}</option>
                                <option value="album">{{ __('messages.settings.grid_album') }}</option>
                            </select>
                            @error('grid_source')<p class="field__error" id="grid_source-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="grid_size">{{ __('messages.settings.grid_size') }}</label>
                            <select id="grid_size" wire:model="grid_size" @if ($describedBy('grid_size')) aria-describedby="{{ $describedBy('grid_size') }}" @endif @error('grid_size') aria-invalid="true" @enderror>
                                @foreach ($gridSizes as $size)
                                    <option value="{{ $size }}">{{ __('messages.settings.grid_layout_'.$size) }}</option>
                                @endforeach
                            </select>
                            @error('grid_size')<p class="field__error" id="grid_size-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <figure class="group__preview">
                        <div class="mosaic" data-size="{{ $gridSize }}" x-bind:data-size="$wire.grid_size" aria-hidden="true">
                            @for ($i = 1; $i <= 9; $i++)<span>{{ $i }}</span>@endfor
                        </div>
                        <figcaption>{{ __('messages.settings.preview') }}</figcaption>
                    </figure>
                </div>
            </fieldset>

            @error('save')
                <div class="alert alert--danger" role="alert">{{ $message }}</div>
            @enderror

            <div class="actions">
                <button type="submit" class="btn-primary" id="save_button" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ __('messages.settings.save') }}</span>
                    <span wire:loading wire:target="save">{{ __('messages.settings.saving') }}</span>
                </button>
                @unless ($firstRun)
                    <button type="button" class="link-button" wire:click="cancel">{{ __('messages.settings.cancel') }}</button>
                @endunless
            </div>
        </form>
    @endif

    @if ($user->social_message)
        <section class="last-post" aria-labelledby="last-post-title">
            <h3 id="last-post-title" class="section__title">{{ __('messages.settings.last_post') }}</h3>
            <div class="sample-post__card">
                <p class="sample-post__text">{{ $user->social_message }}</p>
                @if ($user->social_montage)
                    <a class="last-post__image" href="{{ $user->social_montage }}" target="_blank" rel="noopener">
                        <img src="{{ $user->social_montage }}" alt="" loading="lazy">
                        <span>{{ __('messages.settings.last_post_image') }}{!! $newTab !!}</span>
                    </a>
                @endif
            </div>
        </section>
    @endif

    <section class="danger" aria-labelledby="danger-title">
        <h3 id="danger-title" class="section__title">{{ __('messages.settings.remove_account') }}</h3>
        <p>{{ __('messages.settings.danger_text') }}</p>
        <button type="button" class="btn-danger" id="delete_button" wire:click="deleteAccount" wire:confirm="{{ __('messages.settings.confirm_delete') }}">
            {{ __('messages.settings.remove_account') }}
        </button>
    </section>
</div>
