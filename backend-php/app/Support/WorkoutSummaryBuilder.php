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
     * @param array<string,mixed> $extra Optional extra fields merged at the end (e.g. ['provider' => 'garmin']).
     * @return array<string,mixed>
     */
    public static function build(string $startTimeIso, int $durationSec, int $distanceM, array $extra = []): array
    {
        $base = [
            'startTimeIso' => $startTimeIso,
            'durationSec'  => $durationSec,
            'distanceM'    => $distanceM,
            'original'     => ['durationSec' => $durationSec, 'distanceM' => $distanceM],
            'trimmed'      => ['durationSec' => $durationSec, 'distanceM' => $distanceM],
        ];

        return $extra ? array_merge($base, $extra) : $base;
    }
}
