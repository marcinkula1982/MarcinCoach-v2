<?php

namespace App\Services\Analysis;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserTrainingAnalysisCacheService
{
    public const CACHE_TTL_SECONDS = 600;

    public function __construct(private readonly UserTrainingAnalysisService $analysisService) {}

    /**
     * @return array{analysis:array<string,mixed>,cacheHit:bool}
     */
    public function getForUser(int $userId, int $windowDays = 90): array
    {
        $cacheKey = $this->cacheKey($userId, $windowDays);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return [
                'analysis' => $cached,
                'cacheHit' => true,
            ];
        }

        $analysis = $this->analysisService->analyze($userId, $windowDays)->toArray();
        $this->storeSnapshot($userId, $windowDays, $cacheKey, $analysis);

        Cache::put($cacheKey, $analysis, self::CACHE_TTL_SECONDS);

        return [
            'analysis' => $analysis,
            'cacheHit' => false,
        ];
    }

    private function cacheKey(int $userId, int $windowDays): string
    {
        return sprintf(
            'training_analysis:%s:user:%d:days:%d',
            UserTrainingAnalysisService::SERVICE_VERSION,
            $userId,
            $windowDays,
        );
    }

    /**
     * @param  array<string,mixed>  $analysis
     */
    private function storeSnapshot(int $userId, int $windowDays, string $cacheKey, array $analysis): void
    {
        try {
            DB::table('training_analysis_snapshots')->insert([
                'user_id' => $userId,
                'window_days' => $windowDays,
                'service_version' => (string) ($analysis['serviceVersion'] ?? UserTrainingAnalysisService::SERVICE_VERSION),
                'cache_key' => $cacheKey,
                'computed_at_iso' => (string) ($analysis['computedAt'] ?? now()->utc()->toIso8601String()),
                'snapshot_json' => json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at' => now(),
         