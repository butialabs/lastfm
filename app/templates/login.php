<?php
$this->layout('layout', ['bodyClass' => 'page-login']);
$error = flash('flash');
$protocol = flash('flash_protocol');
?>

<div class="login box">

    <div class="networks" id="networks"<?= $protocol ? ' style="display: none;"' : '' ?>>
        <p>Please select your social network</p>
        <div>
            <button id="network_mastodon">
                <i>
                    <img src="/dist/images/mastodon.svg" />
                </i>
                <span>Mastodon</span>
            </button>
            <button id="network_at">
                <i>
                    <img src="/dist/images/at.svg" />
                </i>
                <span>Bluesky</span>
            </button>
        </div>
    </div>

    <div class="mastodon" id="form_mastodon"<?= $protocol === 'mastodon' ? ' style="display: block;"' : '' ?>>
        <div class="header">
            <button class="network_back">
                < <span>Back</span>
            </button>
        </div>
        <?php if ($error && $protocol === 'mastodon'): ?>
            <div class="alert alert--danger">
                <?= htmlspecialchars($error, ENT_QUOTES) ?>
            </div>
        <?php endif; ?>
        <form method="get" action="/auth/mastodon">
            <div>
                <input type="hidden" name="protocol" value="mastodon">
                <div class="form-row">
                    <input name="instance" placeholder="https://mastodon.social" value="https://mastodon.social" required />
                    <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('login.button'), ENT_QUOTES) ?></button>
                </div>
            </div>
        </form>
    </div>

    <div class="at" id="form_at"<?= $protocol === 'at' ? ' style="display: block;"' : '' ?>>
        <div class="header">
            <button class="network_back">
                < <span>Back</span>
            </button>
        </div>
        <?php if ($error && $protocol === 'at'): ?>
            <div class="alert alert--danger">
                <?= htmlspecialchars($error, ENT_QUOTES) ?>
            </div>
        <?php endif; ?>
        <form method="post" action="/auth/bluesky">
            <div>
                <input type="hidden" name="protocol" value="at">
                <div class="form-row">
                    <input name="instance" placeholder="https://bsky.social" value="https://bsky.social" required />
                </div>
                <div class="form-row">
                    <input name="username" placeholder="chewbacca" required />
                    <input type="password" name="password" required />
                    <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('login.button'), ENT_QUOTES) ?></button>
                </div>
            </div>
        </form>
    </div>
</div>