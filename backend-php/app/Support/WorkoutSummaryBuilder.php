<?php

namespace App\Support;

class WorkoutSummaryBuilder
{
    /**
     * Build the canonical workout summary shape.
     *
     * Flat fields (startTimeIso, durationSec, distanceM) are kept for
     * backward-compatible readers (show, signals v1, plan-compliance).
     * Nested trimmed/original unblock analytics (buildAnalyticsRow).
     *
     * When $parsed is provided (from TcxParsingService::parse), the richer
     * summary fields are written too: sport, hr, avgPaceSecPerKm,
     * intensityBuckets, intensity (legacy-compatible object shape).
     *
     * $extra wins over parsed fields (caller-level overrides, e.g. provider
     * tag from external imports).
     *
     * @param array<string,mixed> $extra
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    public static function build(
        string $startTimeIso,
        int $durationSec,
        int $distanceM,
        array $extra = [],
        array $parsed = [],
    ): array {
        $base = [
            'startTimeIso' => $startTimeIso,
            'durationSec'  => $durationSec,
            'distanceM'    => $distanceM,
            'original'     => ['durationSec' => $durationSec, 'distanceM' => $distanceM],
            'trimmed'      => ['durationSec' => $durationSec, 'distanceM' => $distanceM],
        ];

        if (!empty($parsed)) {
            foreach (['movingTimeSec', 'elapsedTimeSec', 'hrSampleCount'] as $key) {
                if (isset($parsed[$key]) && is_numeric($parsed[$key])) {
                    $base[$key] = (int) $parsed[$key];
                }
            }
            if (isset($parsed['sport']) && is_string($parsed['sport']) && $parsed['sport'] !== '') {
                $base['sport'] = $parsed['sport'];
            }
            if (isset($parsed['hr']) && is_array($parsed['hr'])) {
                $hr = $parsed['hr'];
                // Only persist the canonical keys; silently drop anything else.
                $base['hr'] = [
                    'avgBpm' => isset($hr['avgBpm']) && is_numeric($hr['avgBpm']) ? (int) $hr['avgBpm'] : null,
                    'maxBpm' => isset($hr['maxBpm']) && is_numeric($hr['maxBpm']) ? (int) $hr['maxBpm'] : null,
                ];
            }
            if (isset($parsed['avgPaceSecPerKm']) && is_numeric($parsed['avgPaceSecPerKm'])) {
                $base['avgPaceSecPerKm'] = (int) $parsed['avgPaceSecPerKm'];
            }
            foreach (['elevationGainMeters', 'elevationLossMeters'] as $key) {
                if (isset($parsed[$key]) && is_numeric($parsed[$key])) {
                    $base[$key] = round((float) $parsed[$key], 2);
                }
            }
            foreach (['cadence', 'power', 'paceZones', 'dataAvailability'] as $key) {
                if (isset($parsed[$key]) && is_array($parsed[$key])) {
                    $base[$key] = $parsed[$key];
                }
            }
            if (isset($parsed['fileType']) && is_string($parsed['fileType']) && $parsed['fileType'] !== '') {
                $base['fileType'] = $parsed['fileType'];
            }
            if (isset($parsed['intensityBuckets']) && is_array($parsed['intensityBuckets'])) {
                $buckets = $parsed['intensityBuckets'];
                $z1 = (int) ($buckets['z1Sec'] ?? 0);
                $z2 = (int) ($buckets['z2Sec'] ?? 0);
                $z3 = (int) ($buckets['z3Sec'] ?? 0);
                $z4 = (int) ($buckets['z4Sec'] ?? 0);
                $z5 = (int) ($buckets['z5Sec'] ?? 0);
                $total = isset($buckets['totalSec']) && is_numeric($buckets['totalSec'])
                    ? (int) $buckets['totalSec']
                    : ($z1 + $z2 + $z3 + $z4 + $z5);

                $base['intensityBuckets'] = [
                    'z1Sec' => $z1,
                    'z2Sec' => $z2,
                    'z3Sec' => $z3,
                    'z4Sec' => $z4,
                    'z5Sec' => $z5,
                    'totalSec' => $total,
                ];
                // Legacy shape used by WorkoutsController::buildAnalyticsRow and
                // TrainingFeedbackV2Service (checks `summary.intensity` as object).
                $base['intensity'] = [
                    'z1Sec' => $z1,
                    'z2Sec' => $z2,
                    'z3Sec' => $z3,
                    'z4Sec' => $z4,
                    'z5Sec' => $z5,
                ];
            }
        }

        return $extra ? array_merge($base, $extra) : $base;
    }
}
