<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Services\I18nService;
use App\Services\LastFmService;
use League\Plates\Engine;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class SettingsController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LastFmService $lastfm,
        private readonly I18nService $i18n,
        private readonly Engine $views,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        $userId = session_get('user_id');
        if (!is_int($userId)) {
            return redirect('/');
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            session_destroy_safe();
            return redirect('/');
        }

        $locale = (string) ($user['language'] ?? 'en');
        if (!in_array($locale, ['en', 'pt-BR'], true)) {
            $locale = 'en';
        }
        $_COOKIE['locale'] = $locale;
        $setCookie = $this->i18n->makeLocaleCookieHeader($locale);

        $statusKey = match ((string) ($user['status'] ?? 'ACTIVE')) {
            'SCHEDULE' => 'status.schedule',
            'QUEUED' => 'status.queued',
            'SENDING' => 'status.sending',
            'ERROR' => 'status.error',
            default => 'status.active',
        };

        $tzName = (string) ($user['timezone'] ?? 'UTC');
        $userHour = $this->users->formatScheduleHourForTimezone($user, $tzName);

        $timezones = \DateTimeZone::listIdentifiers();

        $html = $this->views->render('settings', [
            'user' => $user,
            'statusText' => __($statusKey),
            'userHour' => $userHour,
            'timezones' => $timezones,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Set-Cookie' => $setCookie], $html);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        $userId = session_get('user_id');
        if (!is_int($userId)) {
            return redirect('/');
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $lastfmUsername = trim((string) ($body['lastfm_username'] ?? ''));
        $dayOfWeek = (int) ($body['day_of_week'] ?? 0);
        $hour = trim((string) ($body['hour'] ?? ''));
        $timezone = trim((string) ($body['timezone'] ?? ''));

        if ($lastfmUsername === '' || $timezone === '' || $hour === '' || $dayOfWeek < 1 || $dayOfWeek > 7) {
            flash('flash', __('error.missing_fields'));
            return redirect('/settings');
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $hour)) {
            flash('flash', __('error.invalid_time'));
            return redirect('/settings');
        }

        try {
            $tz = new \DateTimeZone($timezone);
        } catch (Throwable) {
            flash('flash', __('error.invalid_timezone'));
            return redirect('/settings');
        }

        try {
            if (!$this->lastfm->validateUser($lastfmUsername)) {
                flash('flash', __('error.lastfm_user_not_found'));
                return redirect('/settings');
            }

            [$utcDow, $utcTime] = $this->users->convertLocalScheduleToUtc(dayOfWeek: $dayOfWeek, hour: $hour, timezone: $tz);

            $this->users->saveSettings(
                userId: $userId,
                lastfmUsername: $lastfmUsername,
                dayOfWeekUtc: $utcDow,
                timeUtc: $utcTime,
                timezone: $timezone,
                status: 'SCHEDULE',
            );

            flash('flash', __('settings.saved'));
            return redirect('/settings');
        } catch (Throwable $e) {
            $this->logger->error('Saving settings failed', ['error' => $e->getMessage()]);
            flash('flash', __('error.generic'));
            return redirect('/settings');
        }
    }
}

