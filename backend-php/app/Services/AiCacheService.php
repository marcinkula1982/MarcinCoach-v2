<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class AiCacheService
{
    private function dayKeyUtc(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d');
    }

    private function ttlToNextUtcMidnight(): \DateTimeInterface
    {
        return CarbonImmutable::now('UTC')->addDay()->startOfDay();
    }

    private function key(string $namespace, int $userId, int $days): string
    {
        return sprintf('ai:%s:%d:%s:days=%d', $namespace, $userId, $this->dayKeyUtc(), $days);
    }

    public function get(string $namespace, int $userId, int $days): mixed
    {
        return Cache::get($this->key($namespace, $userId, $days));
    }

    public function set(string $namespace, int $userId, int $days, mixed $payload): void
    {
        Cache::put($this->key($namespace, $userId, $days), $payload, $this->ttlToNextUtcMidnight());
    }
}
