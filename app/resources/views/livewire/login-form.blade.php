@php
    $newTab = '<span class="visually-hidden"> ('.e(__('messages.login.new_tab')).')</span>';
    // Errors belong to the visible step only; hidden steps keep their fields clean.
    $hasError = fn (string $step, string $field) => $protocol === $step && $errorMessage && ($errorField === $field
        || ($errorField === 'credentials' && in_array($field, ['username', 'password'], true)));
    $describedBy = fn (string $step, string $field, string $hint = '') => trim($hint.' '.($hasError($step, $field) ? 'login-error' : ''));

    // Illustrative only: invented artists, rendered in the exact text format the real post uses.
    $sampleArtists = [['Lua Vermelha', 87], ['Mar de Vidro', 64], ['Casa Fria', 52], ['Vento Sul', 41], ['Neon Tapes', 33]];
    $sampleText = sprintf(
        '♫ %s: %s. #myweekcounted %s #music %s %s',
        sprintf(__('messages.post.top_artists'), count($sampleArtists)),
        implode(' ', array_map(fn ($a) => sprintf('%s (%d)', $a[0], $a[1]), $sampleArtists)),
        sprintf(__('messages.post.scrobbles'), 1204),
        __('messages.post.via'),
        config('lastfm.post.protocols.at.mention', '@lastfm-butialabs.bsky.social'),
    );
    $hidden = fn (string $step) => $protocol === $step ? '' : 'style=display:none';
@endphp
{{-- Steps switch client-side for instant feedback; selectNetwork()/back() only sync the server. --}}
<div
    class="login box"
    x-data="{
        step: @js($protocol),
        go(to) {
            this.step = to;
            document.body.classList.toggle('is-form-step', to !== '');
            const sync = to === '' ? this.$wire.back() : this.$wire.selectNetwork(to);
            // Resolve the heading late: a queued Livewire morph can swap the node and drop focus,
            // so focus it now and again once the server caught up (only if focus fell to <body>).
            const focusTitle = (force) => requestAnimationFrame(() => {
                const lost = ! document.activeElement || document.activeElement === document.body;
                if (this.step === to && (force || lost)) {
                    document.getElementById('step-title-' + (to || 'networks'))?.focus({ preventScroll: true });
                }
            });
            this.$nextTick(() => focusTitle(true));
            Promise.resolve(sync).finally(() => focusTitle(false));
        },
    }"
    x-init="document.body.classList.toggle('is-form-step', step !== '')"
>

    @if ($noticeMessage)
        <div class="alert alert--success" role="status">{{ $noticeMessage }}</div>
    @endif

    <section class="step step--networks" aria-labelledby="step-title-networks" x-show="step === ''" wire:ignore.self {{ $hidden('') }}>
        <div class="step__choose">
            <h2 id="step-title-networks" class="step__title" tabindex="-1" x-ref="title-networks">
                {{ __('messages.login.choose_network') }}
            </h2>
            <div class="networks">
                <button type="button" class="network network--mastodon" @click="go('mastodon')">
                    <span class="network__icon" aria-hidden="true"></span>
                    <span>Mastodon</span>
                </button>
                <button type="button" class="network network--bluesky" @click="go('at')">
                    <span class="network__icon" aria-hidden="true"></span>
                    <span>Bluesky</span>
                </button>
            </div>
        </div>

        <figure class="sample-post">
            <figcaption class="sample-post__label">{{ __('messages.login.sample.caption') }}</figcaption>
            <div class="sample-post__card">
                <div class="sample-post__author">
                    <span class="sample-post__avatar" aria-hidden="true">{{ mb_substr(__('messages.login.sample.name'), 0, 1) }}</span>
                    <span class="sample-post__who">
                        <strong>{{ __('messages.login.sample.name') }}</strong>
                        <span>{{ '@'.__('messages.login.bluesky.handle_placeholder') }}</span>
                    </span>
                </div>
                <p class="sample-post__text">{{ $sampleText }}</p>
                <div class="sample-post__grid" role="img" aria-label="{{ sprintf(__('messages.post.alt_text'), implode(', ', array_column($sampleArtists, 0))) }}">
                    @foreach ($sampleArtists as [$name])
                        <span>{{ $name }}</span>
                    @endforeach
                </div>
            </div>
        </figure>

        <div class="step__how">
            <p class="how__title" id="how-title">{{ __('messages.login.how.title') }}</p>
            <ol class="how" aria-labelledby="how-title">
                <li>{{ __('messages.login.how.connect') }}</li>
                <li>{{ __('messages.login.how.lastfm') }}</li>
                <li>{{ __('messages.login.how.schedule') }}</li>
            </ol>
            @if ($totalUsers > 0)
                <p class="step__trust">
                    <strong>{{ sprintf(trans_choice('messages.login.users_posting', $totalUsers), number_format($totalUsers, 0, '', match (app()->getLocale()) { 'en' => ',', 'fr-FR' => ' ', default => '.' })) }}</strong>
                </p>
            @endif
        </div>
    </section>

    <div class="step__nav" x-show="step !== ''" wire:ignore.self {{ $protocol === '' ? 'style=display:none' : '' }}>
        <button type="button" class="back" @click="go('')">
            <svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>{{ __('messages.login.back') }}</span>
        </button>
    </div>

    <section class="step step--mastodon" aria-labelledby="step-title-mastodon" x-show="step === 'mastodon'" wire:ignore.self {{ $hidden('mastodon') }}>
        <h2 id="step-title-mastodon" class="step__title" tabindex="-1" x-ref="title-mastodon">
            {{ __('messages.login.mastodon.title') }}
        </h2>
        <p class="step__lead">{{ __('messages.login.mastodon.handoff') }}</p>

        <form wire:submit="startMastodon">
            <div class="field">
                <label for="mastodon-instance">{{ __('messages.login.mastodon.instance_label') }}</label>
                <p class="field__hint" id="mastodon-instance-hint">{{ __('messages.login.mastodon.instance_hint') }}</p>
                <div class="field__row">
                    <input
                        id="mastodon-instance"
                        wire:model="instance"
                        name="instance"
                        type="text"
                        inputmode="url"
                        autocapitalize="none"
                        autocorrect="off"
                        spellcheck="false"
                        autocomplete="url"
                        placeholder="mastodon.social"
                        aria-describedby="{{ $describedBy('mastodon', 'instance', 'mastodon-instance-hint') }}"
                        @if ($hasError('mastodon', 'instance')) aria-invalid="true" @endif
                        required
                    />
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="startMastodon">
                        <span wire:loading.remove wire:target="startMastodon">{{ __('messages.login.mastodon.button') }}</span>
                        <span wire:loading wire:target="startMastodon">{{ __('messages.login.mastodon.redirecting') }}</span>
                    </button>
                </div>
            </div>

            @if ($protocol === 'mastodon')
                @error('instance')
                    <div class="alert alert--danger" role="alert">{{ $message }}</div>
                @enderror
                @if ($errorMessage)
                    <div class="alert alert--danger" id="login-error" role="alert">{{ $errorMessage }}</div>
                @endif
            @endif
        </form>
    </section>

    <section class="step step--bluesky" aria-labelledby="step-title-at" x-show="step === 'at'" wire:ignore.self {{ $hidden('at') }}>
        <h2 id="step-title-at" class="step__title" tabindex="-1" x-ref="title-at">
            {{ __('messages.login.bluesky.title') }}
        </h2>
        <p class="step__lead">{{ __('messages.login.bluesky.lead') }}</p>

        <form wire:submit="loginBluesky">
            <div class="field">
                <label for="bluesky-handle">{{ __('messages.login.bluesky.handle_label') }}</label>
                <input
                    id="bluesky-handle"
                    wire:model="username"
                    name="username"
                    type="text"
                    autocomplete="username"
                    autocapitalize="none"
                    autocorrect="off"
                    spellcheck="false"
                    placeholder="{{ __('messages.login.bluesky.handle_placeholder') }}"
                    @if ($hasError('at', 'username')) aria-describedby="login-error" @endif
                    @if ($hasError('at', 'username')) aria-invalid="true" @endif
                    required
                />
            </div>

            <div class="field">
                <label for="bluesky-app-password">{{ __('messages.login.bluesky.app_password_label') }}</label>
                <p class="field__hint" id="bluesky-app-password-hint">{!! sprintf(e(__('messages.login.bluesky.app_password')), '<a href="https://bsky.app/settings/app-passwords" target="_blank" rel="noopener">'.e(__('messages.login.bluesky.app_password_link')).$newTab.'</a>') !!}</p>
                <details class="howto">
                    <summary>{{ __('messages.login.bluesky.howto.summary') }}</summary>
                    <ol>
                        <li>{{ __('messages.login.bluesky.howto.step1') }}</li>
                        <li>{{ __('messages.login.bluesky.howto.step2') }}</li>
                        <li>{{ __('messages.login.bluesky.howto.step3') }}</li>
                    </ol>
                </details>
                <div class="field__password" x-data="{ shown: false }">
                    <input
                        id="bluesky-app-password"
                        wire:model="password"
                        type="password"
                        x-bind:type="shown ? 'text' : 'password'"
                        name="password"
                        autocomplete="current-password"
                        autocapitalize="none"
                        autocorrect="off"
                        spellcheck="false"
                        placeholder="xxxx-xxxx-xxxx-xxxx"
                        aria-describedby="{{ $describedBy('at', 'password', 'bluesky-app-password-hint') }}"
                        @if ($hasError('at', 'password')) aria-invalid="true" @endif
                        required
                    />
                    <button type="button" class="reveal" aria-controls="bluesky-app-password" aria-pressed="false" x-bind:aria-pressed="shown.toString()" @click="shown = !shown">
                        <svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                            <circle cx="12" cy="12" r="3"/>
                            <path x-show="shown" d="M4 4l16 16" style="display:none"/>
                        </svg>
                        <span class="visually-hidden">{{ __('messages.login.bluesky.show_password') }}</span>
                    </button>
                </div>
            </div>

            @if ($customServer)
                <div class="field">
                    <label for="bluesky-instance">{{ __('messages.login.bluesky.server_label') }}</label>
                    <input
                        id="bluesky-instance"
                        wire:model="instance"
                        name="instance"
                        type="text"
                        inputmode="url"
                        autocapitalize="none"
                        autocorrect="off"
                        spellcheck="false"
                        placeholder="https://bsky.social"
                        @if ($hasError('at', 'instance')) aria-describedby="login-error" @endif
                        @if ($hasError('at', 'instance')) aria-invalid="true" @endif
                        required
                    />
                </div>
            @else
                <button type="button" class="link-button" wire:click="$set('customServer', true)">
                    {{ __('messages.login.bluesky.custom_server') }}
                </button>
            @endif

            @if ($protocol === 'at')
                @foreach (['username', 'password', 'instance'] as $field)
                    @error($field)
                        <div class="alert alert--danger" role="alert">{{ $message }}</div>
                    @enderror
                @endforeach
                @if ($errorMessage)
                    <div class="alert alert--danger" id="login-error" role="alert">{{ $errorMessage }}</div>
                @endif
            @endif

            <button type="submit" class="btn-primary btn-primary--block" wire:loading.attr="disabled" wire:target="loginBluesky">
                <span wire:loading.remove wire:target="loginBluesky">{{ __('messages.login.bluesky.button') }}</span>
                <span wire:loading wire:target="loginBluesky">{{ __('messages.login.bluesky.connecting') }}</span>
            </button>
        </form>
    </section>
</div>
