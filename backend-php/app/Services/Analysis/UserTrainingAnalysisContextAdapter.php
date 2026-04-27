<?php

namespace App\Services\Analysis;

use Carbon\CarbonImmutable;

class UserTrainingAnalysisContextAdapter
{
    /**
     * @param  array<string,mixed>  $analysis
     * @param  array<string,mixed>|null  $legacySignals
     * @return array<string,mixed>
     */
    public function toSignals(array $analysis, ?array $legacySignals = null): array
    {
        $legacySignals ??= [];
        $facts = is_array($analysis['facts'] ?? null) ? $analysis['facts'] : [];
        $legacyFlags = is_array($legacySignals['flags'] ?? null) ? $legacySignals['flags'] : [];

        $windowDays = max(1, (int) ($analysis['windowDays'] ?? $legacySignals['windowDays'] ?? 28));
        $windowEnd = $this->resolveWindowEnd($analysis, $legacySignals);
        $windowStart = $windowEnd->subDays($windowDays);

        $codes = $this->codes($analysis['planImplications'] ?? []);
        $loadSpike = (bool) ($facts['spikeLoad'] ?? false) || in_array('load_spike', $codes, true);
        $overallFatigueSpike = (bool) ($facts['overallFatigueSpike'] ?? false);
        $returnAfterBreak = in_array('return_after_break', $codes, true);
        $runningLoad7d = $this->numberOrZero($facts['runningLoad7d'] ?? $facts['load7d'] ?? null);
        $runningLoad28d = $this->numberOrZero($facts['runningLoad28d'] ?? $facts['load28d'] ?? null);
        $crossTrainingFatigue7d = $this->numberOrZero($facts['crossTrainingFatigue7d'] ?? null);
        $overallFatigue7d = $this->numberOrZero($facts['overallFatigue7d'] ?? $facts['load7d'] ?? null);

        return [
            'generatedAtIso' => $windowEnd->toISOString(),
            'windowDays' => $windowDays,
            'windowStart' => $windowStart->toISOString(),
            'windowEnd' => $windowEnd->toISOString(),
            'weeklyLoad' => $runningLoad7d,
            'rolling4wLoad' => $runningLoad28d,
            'runningLoad' => $runningLoad7d,
            'runningLoad7d' => $runningLoad7d,
            'runningLoad28d' => $runningLoad28d,
            'crossTrainingFatigueLoad' => $crossTrainingFatigue7d,
            'crossTrainingFatigue7d' => $crossTrainingFatigue7d,
            'crossTrainingFatigue28d' => $this->numberOrZero($facts['crossTrainingFatigue28d'] ?? null),
            'overallFatigueLoad' => $overallFatigue7d,
            'overallFatigue7d' => $overallFatigue7d,
            'overallFatigue28d' => $this->numberOrZero($facts['overallFatigue28d'] ?? $facts['load28d'] ?? null),
            'acwrRunning' => is_numeric($facts['acwrRunning'] ?? null) ? (float) $facts['acwrRunning'] : null,
            'acwrOverall' => is_numeric($facts['acwrOverall'] ?? null) ? (float) $facts['acwrOverall'] : null,
            'buckets' => $this->buckets($legacySignals['buckets'] ?? null),
            'longRun' => $this->longRun($facts, $legacySignals),
            'flags' => [
                'injuryRisk' => $returnAfterBreak || (bool) ($legacyFlags['injuryRisk'] ?? false),
                'fatigue' => $loadSpike || $overallFatigueSpike || (bool) ($legacyFlags['fatigue'] ?? false),
                'loadSpike' => $loadSpike,
                'overallFatigueSpike' => $overallFatigueSpike,
                'returnAfterBreak' => $returnAfterBreak,
            ],
            'adaptation' => $this->adaptation($legacySignals),
            'totalWorkouts' => (int) ($facts['workoutCount'] ?? 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @param  array<string,mixed>  $legacySignals
     */
    private function resolveWindowEnd(array $analysis, array $legacySignals): CarbonImmutable
    {
        foreach ([
            $analysis['computedAt'] ?? null,
            $legacySignals['windowEnd'] ?? null,
            $legacySignals['generatedAtIso'] ?? null,
        ] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            try {
                return CarbonImmutable::parse($candidate)->utc();
            } catch (\Throwable) {
            }
        }

        return CarbonImmutable::now('UTC');
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  array<string,mixed>  $legacySignals
     * @return array{exists:bool,distanceKm:float,workoutId:mixed,workoutDt:mixed}
     */
    private function longRun(array $facts, array $legacySignals): array
    {
        $legacyLongRun = is_array($legacySignals['longRun'] ?? null) ? $legacySignals['longRun'] : [];
        $longestMeters = $facts['longestRunMeters'] ?? null;

        $distanceKm = is_numeric($longestMeters)
            ? round(((float) $longestMeters) / 1000.0, 2)
            : $this->numberOrZero($legacyLongRun['distanceKm'] ?? null);

        return [
            'exists' => $distanceKm > 0,
            'distanceKm' => $distanceKm,
            'workoutId' => $legacyLongRun['workoutId'] ?? null,
            'workoutDt' => $legacyLongRun['workoutDt'] ?? null,
        ];
    }

    /**
     * @return array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float,totalSec:float}
     */
    private function buckets(mixed $value): array
    {
        $source = is_array($value) ? $value : [];

        return [
            'z1Sec' => $this->numberOrZero($source['z1Sec'] ?? null),
            'z2Sec' => $this->numberOrZero($source['z2Sec'] ?? null),
            'z3Sec' => $this->numberOrZero($source['z3Sec'] ?? null),
            'z4Sec' => $this->numberOrZero($source['z4Sec'] ?? null),
            'z5Sec' => $this->numberOrZero($source['z5Sec'] ?? null),
            'totalSec' => $this->numberOrZero($source['totalSec'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $legacySignals
     * @return array<string,mixed>
     */
    private function adaptation(array $legacySignals): array
    {
        $legacy = is_array($legacySignals['adaptation'] ?? null) ? $legacySignals['adaptation'] : [];
        $adaptation = array_replace([
            'missedKeyWorkout' => false,
            'harderThanPlanned' => false,
            'easierThanPlannedStreak' => 0,
            'controlStartRecent' => false,
        ], $legacy);

        if (($adaptation['controlStartRecent'] ?? false) === true && ! array_key_exists('postRaceWeek', $adaptation)) {
            $adaptation['postRaceWeek'] = true;
        }

        return $adaptation;
    }

    private function numberOrZero(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @return list<string>
     */
    private function codes(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $codes = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['code'] ?? null)) {
                continue;
            }
            $codes[] = $item['code'];
        }

        return $codes;
    }
}
