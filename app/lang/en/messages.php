<?php

declare(strict_types=1);

return [
    'app.title' => 'Share your top artists weekly!',
    'app.description' => 'Share automatically your top Last.fm artists from the past week on a selected day using your Bluesky or Mastodon account',
    'app.language' => 'Language',

    'footer.made_with_love' => 'Made with ❤️ by',
    'footer.total_users' => '{0} %d users|{1} %d user|[2,*] %d users',

    'login.button' => 'Login',
    'login.bluesky.app_password' => 'You\'ll need to generate an %s in order to login, this is for your own security.',
    'login.bluesky.app_password_link' => 'App Password',
    'placeholder_username' => 'Identifier (Handle, Email or DID)',
    'placeholder_password' => 'Password',
    'placeholder_instance_mastodon' => 'Instance URL (e.g.: https://mastodon.social)',
    'placeholder_instance_atproto' => 'Instance URL (e.g.: https://bsky.social)',

    'settings.logout' => 'Logout',
    'settings.lastfm_username' => 'Last.fm username',
    'settings.day_of_week' => 'Day of the Week',
    'settings.hour' => 'Hour',
    'settings.save' => 'Save',
    'settings.edit' => 'Edit',
    'settings.remove_account' => 'Remove account',
    'settings.confirm_delete' => 'Do you really want to delete your account?',
    'settings.saved' => 'Settings saved.',
    'settings.last_update' => 'Last update',
    'settings.with_montage' => 'with',
    'settings.montage' => 'montage',

    'day.sunday' => 'Sunday',
    'day.monday' => 'Monday',
    'day.tuesday' => 'Tuesday',
    'day.wednesday' => 'Wednesday',
    'day.thursday' => 'Thursday',
    'day.friday' => 'Friday',
    'day.saturday' => 'Saturday',

    'status.active' => 'Your account is active. Please fill in your Last.fm details to schedule weekly posts.',
    'status.schedule' => 'Your account is scheduled for weekly posts.',
    'status.queued' => 'Your post is queued for processing. It will be sent soon.',
    'status.sending' => 'Your post is currently being sent.',
    'status.error' => 'An error occurred. The system will try again in the next routine.',

    'post.top_artists' => 'Top 5 artists of the Last.week',
    'post.scrobbles' => '%d Scrobbles with Lastfm',
    'post.via' => 'via',
    'post.alt_text' => 'Collage of photos of the bands %s',

    'auth.logged_out' => 'Logged out.',

    'error.missing_fields' => 'Please fill all required fields.',
    'error.auth_failed' => 'Authentication failed.',
    'error.lastfm_user_not_found' => 'Last.fm user not found.',
    'error.invalid_timezone' => 'Invalid timezone.',
    'error.invalid_time' => 'Invalid time format.',
    'error.generic' => 'Something went wrong.',

    'admin.config.saved' => 'Settings saved successfully.',
];
