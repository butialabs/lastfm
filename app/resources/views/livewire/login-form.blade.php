<div class="login box">

    @if ($protocol === '')
        <div class="networks" id="networks">
            <p>Please select your social network</p>
            <div>
                <button type="button" id="network_mastodon" wire:click="selectNetwork('mastodon')">
                    <i>
                        <img src="/images/mastodon.svg" />
                    </i>
                    <span>Mastodon</span>
                </button>
                <button type="button" id="network_at" wire:click="selectNetwork('at')">
                    <i>
                        <img src="/images/at.svg" />
                    </i>
                    <span>Bluesky</span>
                </button>
            </div>
        </div>
    @endif

    @if ($protocol === 'mastodon')
        <div class="mastodon" id="form_mastodon" style="display: block;">
            <div class="header">
                <button type="button" class="network_back" wire:click="back">
                    < <span>Back</span>
                </button>
            </div>
            @if ($errorMessage)
                <div class="alert alert--danger">
                    {{ $errorMessage }}
                </div>
            @endif
            <form wire:submit="startMastodon">
                <div>
                    <div class="form-row">
                        <input wire:model="instance" name="instance" placeholder="{{ __('messages.placeholder_instance_mastodon') }}" required />
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="startMastodon">
                            <span wire:loading.remove wire:target="startMastodon">{{ __('messages.login.button') }}</span>
                            <span wire:loading wire:target="startMastodon">...</span>
                        </button>
                    </div>
                    @error('instance')
                        <div class="alert alert--danger">{{ $message }}</div>
                    @enderror
                </div>
            </form>
        </div>
    @endif

    @if ($protocol === 'at')
        <div class="at" id="form_at" style="display: block;">
            <div class="header">
                <button type="button" class="network_back" wire:click="back">
                    < <span>Back</span>
                </button>
            </div>
            @if ($errorMessage)
                <div class="alert alert--danger">
                    {{ $errorMessage }}
                </div>
            @endif
            <form wire:submit="loginBluesky">
                <div>
                    <div class="form-row">
                        <input wire:model="instance" name="instance" placeholder="{{ __('messages.placeholder_instance_atproto') }}" required />
                    </div>
                    <div class="form-row">
                        <input wire:model="username" name="username" placeholder="{{ __('messages.placeholder_username') }}" required />
                        <input wire:model="password" type="password" name="password" placeholder="{{ __('messages.placeholder_password') }}" required />
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="loginBluesky">
                            <span wire:loading.remove wire:target="loginBluesky">{{ __('messages.login.button') }}</span>
                            <span wire:loading wire:target="loginBluesky">...</span>
                        </button>
                    </div>
                    @error('username')
                        <div class="alert alert--danger">{{ $message }}</div>
                    @enderror
                    @error('password')
                        <div class="alert alert--danger">{{ $message }}</div>
                    @enderror
                    <p class="at_alert_app_password">{!! sprintf(__('messages.login.bluesky.app_password'), '<a href="https://bsky.app/settings/app-passwords" target="_blank">'.e(__('messages.login.bluesky.app_password_link')).'</a>') !!}</p>
                </div>
            </form>
        </div>
    @endif
</div>
