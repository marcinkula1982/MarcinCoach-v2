<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SessionTokenService
{
    public function issueToken(int $userId, string $username): string
    {
        $token = (string) Str::uuid();
        $payload = [
            'userId' => $userId,
            'username' => $username,
        ];

        Cache::put($this->key($token), $payload, now()->addDays(30));

        return $token;
    }

    public function resolveUserId(string $token, string $username): ?int
    {
        $payload = Cache::get($this->key($token));
        if (!is_array($payload)) {
            return null;
        }

        $tokenUsername = (string) ($payload['username'] ?? '');
        $tokenUserId = $payload['userId'] ?? null;
        if ($tokenUsername === '' || !is_numeric($tokenUserId)) {
            return null;
        }
        if (strcasecmp($tokenUsername, $username) !== 0) {
            return null;
        }

        return (int) $tokenUserId;
    }

    public function revokeToken(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'session_token:' . $token;
    }
}
