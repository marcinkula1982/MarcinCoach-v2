<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class AiRateLimitService
{
    private function dayKeyUtc(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d');
    }

    private function resetAtIsoUtc(): string
    {
        return CarbonImmutable::now('UTC')->addDay()->startOfDay()->toISOString();
    }

    /**
     * @return array{allowed:bool,used:int,limit:int,remaining:int,resetAtIsoUtc:string}
     */
    public function consume(int $userId, int $limit): array
    {
        $key = sprintf('ai-rate-limit:%d:%s', $userId, $this->dayKeyUtc());
        $ttlAt = CarbonImmutable::now('UTC')->addDay()->startOfDay();
        $used = (int) Cache::get($key, 0);
        if ($used >= $limit) {
            return [
                'allowed' => false,
                'used' => $used,
                'limit' => $limit,
                'remaining' => 0,
                'resetAtIsoUtc' => $this->resetAtIsoUtc(),
            ];
        }

        $used++;
        Cache::put($key, $used, $ttlAt);

        return [
            'allowed' => true,
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'resetAtIsoUtc' => $this->resetAtIsoUtc(),
        ];
    }
}
