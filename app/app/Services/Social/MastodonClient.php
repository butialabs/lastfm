<?php

declare(strict_types=1);

namespace App\Services\Social;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class MastodonClient
{
    private const APP_USER_AGENT = 'LastFM.butialabs.com/1.0';

    public function normalizeInstance(string $instance): string
    {
        $instance = trim($instance);
        if ($instance === '') {
            return 'https://mastodon.social';
        }
        if (! str_starts_with($instance, 'http')) {
            $instance = 'https://'.$instance;
        }

        return rtrim($instance, '/');
    }

    /** @return array{client_id:string,client_secret:string} */
    public function registerApp(string $instance, string $appName, string $redirectUri): array
    {
        $base = $this->normalizeInstance($instance);
        $res = $this->request()->asForm()->post($base.'/api/v1/apps', [
            'client_name' => $appName,
            'redirect_uris' => $redirectUri,
            'scopes' => 'read write',
            'website' => (string) config('app.url', ''),
        ]);

        $json = $res->json();
        if (! is_array($json) || ! isset($json['client_id'], $json['client_secret'])) {
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

        return $base.'/oauth/authorize?'.$q;
    }

    public function exchangeToken(string $instance, string $clientId, string $clientSecret, string $redirectUri, string $code): string
    {
        $base = $this->normalizeInstance($instance);
        $res = $this->request()->asForm()->post($base.'/oauth/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'scope' => 'read write',
        ]);

        $json = $res->json();
        if (! is_array($json) || ! isset($json['access_token'])) {
            throw new \RuntimeException('Invalid token response');
        }

        return (string) $json['access_token'];
    }

    /** @return array<string,mixed> */
    public function verifyCredentials(string $instance, string $token): array
    {
        $base = $this->normalizeInstance($instance);
        $res = $this->request()
            ->withToken($token)
            ->get($base.'/api/v1/accounts/verify_credentials');

        $json = $res->json();
        if (! is_array($json)) {
            throw new \RuntimeException('Invalid verify_credentials response');
        }

        return $json;
    }

    public function uploadMedia(string $instance, string $token, string $absoluteImagePath, ?string $altText = null): string
    {
        $base = $this->normalizeInstance($instance);

        $bin = file_get_contents($absoluteImagePath);
        if ($bin === false) {
            throw new \RuntimeException('Unable to read image: '.$absoluteImagePath);
        }

        $request = $this->request()
            ->withToken($token)
            ->attach('file', $bin, basename($absoluteImagePath));

        if ($altText !== null) {
            $request = $request->attach('description', $altText);
        }

        $res = $request->post($base.'/api/v2/media');

        $json = $res->json();
        if (! is_array($json) || ! isset($json['id'])) {
            throw new \RuntimeException('Invalid media upload response');
        }

        return (string) $json['id'];
    }

    /** @return array<string,mixed> */
    public function postStatus(string $instance, string $token, string $text, ?string $mediaId, ?string $inReplyToId): array
    {
        $base = $this->normalizeInstance($instance);

        $form = ['status' => $text];
        if ($mediaId !== null) {
            $form['media_ids[]'] = $mediaId;
        }
        if ($inReplyToId !== null) {
            $form['in_reply_to_id'] = $inReplyToId;
        }

        $res = $this->request()
            ->withToken($token)
            ->asForm()
            ->post($base.'/api/v1/statuses', $form);

        $json = $res->json();
        if (! is_array($json) || ! isset($json['id'])) {
            throw new \RuntimeException('Invalid status post response');
        }

        return $json;
    }

    private function request(): PendingRequest
    {
        return Http::withUserAgent(self::APP_USER_AGENT)
            ->withOptions(['http_errors' => false])
            ->timeout(25)
            ->connectTimeout(15);
    }
}
