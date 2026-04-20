<?php

namespace App\Services;

use App\Models\IntegrationAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class StravaOAuthService
{
    public function buildConnectUrl(int $userId): array
    {
        $clientId = (string) env('STRAVA_CLIENT_ID', '');
        $redirectUri = (string) env('STRAVA_REDIRECT_URI', '');
        $scopes = (string) env('STRAVA_SCOPES', 'activity:read_all,profile:read_all');
        $state = bin2hex(random_bytes(16));
        Cache::put($this->stateKey($userId, $state), '1', now()->addMinutes(10));

        $url = 'https://www.strava.com/oauth/authorize?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'approval_prompt' => 'auto',
            'scope' => $scopes,
            'state' => $state,
        ]);

        return ['url' => $url, 'state' => $state];
    }

    public function handleCallback(int $userId, string $code, string $state): array
    {
        if (!Cache::pull($this->stateKey($userId, $state))) {
            return ['ok' => false, 'error' => 'INVALID_STATE'];
        }

        $clientId = (string) env('STRAVA_CLIENT_ID', '');
        $clientSecret = (string) env('STRAVA_CLIENT_SECRET', '');
        if ($clientId === '' || $clientSecret === '') {
            return ['ok' => false, 'error' => 'STRAVA_NOT_CONFIGURED'];
        }

        $res = Http::asForm()->timeout(30)->post('https://www.strava.com/oauth/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);
        if (!$res->successful()) {
            return ['ok' => false, 'error' => 'STRAVA_TOKEN_EXCHANGE_FAILED'];
        }
        $json = $res->json();
        $account = IntegrationAccount::query()->firstOrNew([
            'user_id' => $userId,
            'provider' => 'strava',
        ]);
        $account->external_user_id = (string) data_get($json, 'athlete.id', '');
        $account->access_token = (string) ($json['access_token'] ?? '');
        $account->refresh_token = (string) ($json['refresh_token'] ?? '');
        $account->access_token_expires_at = isset($json['expires_at']) ? now()->setTimestamp((int) $json['expires_at']) : null;
        $account->status = 'connected';
        $account->meta = [
            'scope' => (string) ($json['scope'] ?? ''),
            'athlete' => data_get($json, 'athlete'),
        ];
        $account->save();

        return ['ok' => true, 'accountId' => $account->id];
    }

    public function getValidAccessToken(int $userId): ?string
    {
        $account = IntegrationAccount::query()->where('user_id', $userId)->where('provider', 'strava')->first();
        if (!$account) {
            return null;
        }
        $expiresAt = $account->access_token_expires_at;
        if ($account->access_token && $expiresAt && $expiresAt->gt(now()->addMinutes(2))) {
            return $account->access_token;
        }

        $clientId = (string) env('STRAVA_CLIENT_ID', '');
        $clientSecret = (string) env('STRAVA_CLIENT_SECRET', '');
        if ($clientId === '' || $clientSecret === '' || !$account->refresh_token) {
            return null;
        }

        $res = Http::asForm()->timeout(30)->post('https://www.strava.com/oauth/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);
        if (!$res->successful()) {
            $account->status = 'degraded';
            $account->save();
            return null;
        }
        $json = $res->json();
        $account->access_token = (string) ($json['access_token'] ?? '');
        $account->refresh_token = (string) ($json['refresh_token'] ?? $account->refresh_token);
        $account->access_token_expires_at = isset($json['expires_at']) ? now()->setTimestamp((int) $json['expires_at']) : null;
        $account->status = 'connected';
        $account->save();
        return $account->access_token;
    }

    private function stateKey(int $userId, string $state): string
    {
        return sprintf('strava:oauth:state:%d:%s', $userId, $state);
    }
}
