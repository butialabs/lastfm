<?php

declare(strict_types=1);

namespace App\Services\Social;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\Utils;
use Psr\Log\LoggerInterface;

final class MastodonClient
{
    public function __construct(
        private readonly Guzzle $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function normalizeInstance(string $instance): string
    {
        $instance = trim($instance);
        if ($instance === '') {
            return 'https://mastodon.social';
        }
        if (!str_starts_with($instance, 'http')) {
            $instance = 'https://' . $instance;
        }
        return rtrim($instance, '/');
    }

    /** @return array{client_id:string,client_secret:string} */
    public function registerApp(string $instance, string $appName, string $redirectUri): array
    {
        $base = $this->normalizeInstance($instance);
        $res = $this->http->post($base . '/api/v1/apps', [
            'form_params' => [
                'client_name' => $appName,
                'redirect_uris' => $redirectUri,
                'scopes' => 'read write',
                'website' => (string) ($_ENV['APP_URL'] ?? ''),
            ],
        ]);
        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json) || !isset($json['client_id'], $json['client_secret'])) {
            throw new \RuntimeException('Invalid /api/v1/apps response');
        }
        return ['client_id' => (string) $json['client_id'], 'client_secret' => (string) $json['client_secret']];
    }

    public function getAuthorizeUrl(string $instance, string $clientId, string $redirectUri, string $scope, string $state): string
    {
        $base = $this->normalizeInstance($instance);
        $q = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
        ]);
        return $base . '/oauth/authorize?' . $q;
    }

    public function exchangeToken(string $instance, string $clientId, string $clientSecret, string $redirectUri, string $code): string
    {
        $base = $this->normalizeInstance($instance);
        $res = $this->http->post($base . '/oauth/token', [
            'form_params' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'scope' => 'read write',
            ],
        ]);
        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json) || !isset($json['access_token'])) {
            throw new \RuntimeException('Invalid token response');
        }
        return (string) $json['access_token'];
    }

    /** @return array<string,mixed> */
    public function verifyCredentials(string $instance, string $token): array
    {
        $base = $this->normalizeInstance($instance);
        $res = $this->http->get($base . '/api/v1/accounts/verify_credentials', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid verify_credentials response');
        }
        return $json;
    }

    public function uploadMedia(string $instance, string $token, string $absoluteImagePath): string
    {
        $base = $this->normalizeInstance($instance);
        $res = $this->http->post($base . '/api/v2/media', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => Utils::tryFopen($absoluteImagePath, 'r'),
                    'filename' => basename($absoluteImagePath),
                ],
            ],
        ]);
        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json) || !isset($json['id'])) {
            throw new \RuntimeException('Invalid media upload response');
        }
        return (string) $json['id'];
    }

    /** @return array<string,mixed> */
    public function postStatus(string $instance, string $token, string $text, ?string $mediaId, ?string $inReplyToId): array
    {
        $base = $this->normalizeInstance($instance);
        $form = [
            'status' => $text,
        ];
        if ($mediaId !== null) {
            $form['media_ids[]'] = $mediaId;
        }
        if ($inReplyToId !== null) {
            $form['in_reply_to_id'] = $inReplyToId;
        }

        $res = $this->http->post($base . '/api/v1/statuses', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => $form,
        ]);
        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json) || !isset($json['id'])) {
            throw new \RuntimeException('Invalid status post response');
        }
        return $json;
    }
}

