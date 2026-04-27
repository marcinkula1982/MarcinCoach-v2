<?php

namespace App\Services;

use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TrainingSignalsService
{
    /**
     * @deprecated F7: new planning, alerts and feedback-v2 paths should use
     * UserTrainingAnalysisService. This method remains for the public
     * /training-signals compatibility endpoint and v1 backfills.
     */
    public function getSignalsForUser(int $userId, int $days = 28): array
    {
        $workouts = Workout::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get(['id', 'created_at', 'summary', 'kind', 'race_meta']);

        $rows = $workouts->map(function (Workout $workout) {
            $summary = is_array($workout->summary) ? $workout->summary : [];
            $workoutDt = $this->getWorkoutDt($summary, $workout->created_at);
            $buckets = $this->extractBuckets($summary);
            $loadValue = $this->extractLoadValue($summary, $buckets);
            $distanceM = $this->toNumberOrZero($summary['trimmed']['distanceM'] ?? $summary['original']['distanceM'] ?? $summary['distanceM'] ?? null);
            $sport = is_string($summary['sport'] ?? null) ? strtolower((string) $summary['sport']) : null;

            return [
                'id' => $workout->id,
                'workoutDt' => $workoutDt,
                'loadValue' => $loadValue,
                'buckets' => $buckets,
                'distanceKm' => $distanceM > 0 ? $distanceM / 1000 : 0.0,
                'kind' => (string) ($workout->kind ?? 'training'),
                'raceMeta' => is_array($workout->race_meta) ? $workout->race_meta : null,
                'sport' => $sport,
            ];
        })->values();

        // windowEnd is anchored to "now" (UTC) regardless of whether there are
        // workouts yet. This keeps the 7d/28d windows stable across imports and
        // makes "last 7d" / "last 28d" mean what the coaching spec says.
        $windowEnd = now()->utc();
        $windowStart = $windowEnd->copy()->subSeconds($days * 24 * 60 * 60);

        $filtered = $rows
            ->filter(fn (array $row) => $row['workoutDt']->greaterThanOrEqualTo($windowStart) && $row['workoutDt']->lessThanOrEqualTo($windowEnd))
            ->values();

        $weeklyFrom = $windowEnd->copy()->subDays(7);
        $weeklyLoad = $filtered
            ->filter(fn (array $row) => $row['workoutDt']->greaterThan($weeklyFrom))
            ->sum('loadValue');
        $rolling4wLoad = $filtered->sum('loadValue');

        // Safety minimums (internal calculations, mapped onto existing public flags):
        // - volume growth guard (weekly vs previous week)
        // - return-after-break guard
        // - post-race easy-week guard
        $prevWeeklyFrom = $weeklyFrom->copy()->subDays(7);
        $prevWeeklyTo = $weeklyFrom;
        $prevWeeklyLoad = $filtered
            ->filter(fn (array $row) => $row['workoutDt']->greaterThan($prevWeeklyFrom) && $row['workoutDt']->lessThanOrEqualTo($prevWeeklyTo))
            ->sum('loadValue');
        $loadSpike = $prevWeeklyLoad > 0 && $weeklyLoad > ($prevWeeklyLoad * 1.30);

        $sortedByDt = $rows->sortBy(fn (array $row) => $row['workoutDt']->getTimestamp())->values();
        $lastWorkout = $sortedByDt->isNotEmpty() ? $sortedByDt->last() : null;
        $prevWorkout = null;
        if (is_array($lastWorkout)) {
            $prevWorkout = $sortedByDt
                ->filter(fn (array $row) => $row['workoutDt']->lessThan($lastWorkout['workoutDt']))
                ->last();
        }

        $returnAfterBreak = false;
        if (is_array($lastWorkout) && is_array($prevWorkout)) {
            $gapDays = $lastWorkout['workoutDt']->diffInDays($prevWorkout['workoutDt']);
            $returnAfterBreak = $gapDays >= 10;
        }

        $postRaceWeek = false;
        if (is_array($lastWorkout)) {
            $isRaceKind = strtolower((string) ($lastWorkout['kind'] ?? '')) === 'race';
            $hasRaceMeta = is_array($lastWorkout['raceMeta'] ?? null) && ! empty($lastWorkout['raceMeta']);
            $postRaceWeek = $isRaceKind || $hasRaceMeta;
        }

        $bucketTotals = $this->emptyBuckets();
        foreach ($filtered as $row) {
            $bucketTotals = $this->addBuckets($bucketTotals, $row['buckets']);
        }

        // Long run: pick workout with max distance in window among running sessions.
        // Legacy fallback: if no row declares sport='run' at all, treat any workout as
        // eligible so old data (pre M2-beyond summaries) still surfaces a long run.
        $hasSportTagged = false;
        foreach ($filtered as $row) {
            if (is_string($row['sport'] ?? null) && $row['sport'] !== '') {
                $hasSportTagged = true;
                break;
            }
        }
        $longRunRow = null;
        foreach ($filtered as $row) {
            $sport = $row['sport'] ?? null;
            $isRun = $sport === 'run';
            $legacyEligible = ! $hasSportTagged && $sport === null;
            if (! $isRun && ! $legacyEligible) {
                continue;
            }
            if ($longRunRow === null || $row['distanceKm'] > $longRunRow['distanceKm']) {
                $longRunRow = $row;
            }
        }
        $longRun = [
            'exists' => $longRunRow !== null && $longRunRow['distanceKm'] > 0,
            'distanceKm' => $longRunRow !== null ? (float) round($longRunRow['distanceKm'], 2) : 0.0,
            'workoutId' => $longRunRow !== null ? $longRunRow['id'] : null,
            'workoutDt' => $longRunRow !== null ? $longRunRow['workoutDt']->toISOString() : null,
        ];

        $adaptation = $this->buildAdaptationSignals($userId, $filtered->all(), $windowEnd, $lastWorkout);

        return [
            'generatedAtIso' => $windowEnd->toISOString(),
            'windowDays' => $days,
            'windowStart' => $windowStart->toISOString(),
            'windowEnd' => $windowEnd->toISOString(),
            'weeklyLoad' => (float) round($weeklyLoad, 0),
            'rolling4wLoad' => (float) round($rolling4wLoad, 0),
            'buckets' => [
                'z1Sec' => (float) round($bucketTotals['z1Sec'], 0),
                'z2Sec' => (float) round($bucketTotals['z2Sec'], 0),
                'z3Sec' => (float) round($bucketTotals['z3Sec'], 0),
                'z4Sec' => (float) round($bucketTotals['z4Sec'], 0),
                'z5Sec' => (float) round($bucketTotals['z5Sec'], 0),
                'totalSec' => (float) round($bucketTotals['totalSec'], 0),
            ],
            'longRun' => $longRun,
            'flags' => [
                // Keep public contract shape unchanged; only values become data-driven.
                'injuryRisk' => (bool) ($returnAfterBreak || $postRaceWeek),
                'fatigue' => (bool) $loadSpike,
            ],
            'adaptation' => $adaptation,
            'totalWorkouts' => $filtered->count(),
        ];
    }

    public function upsertForWorkout(int $workoutId): void
    {
        $workout = Workout::find($workoutId);
        if (! $workout) {
            return;
        }

        $summary = $workout->summary ?? [];
        $durationSec = $summary['durationSec'] ?? 0;
        $distanceM = $summary['distanceM'] ?? 0;

        // Calculate avg_pace_sec_per_km
        $avgPaceSecPerKm = null;
        if ($distanceM > 0) {
            $avgPaceSecPerKm = (int) round($durationSec / ($distanceM / 1000));
        }

        // Determine duration_bucket
        $durationBucket = 'UNKNOWN';
        if ($durationSec > 0) {
            if ($durationSec < 20 * 60) {
                $durationBucket = 'DUR_SHORT';
            } elseif ($durationSec <= 70 * 60) {
                $durationBucket = 'DUR_MEDIUM';
            } else {
                $durationBucket = 'DUR_LONG';
            }
        }

        // Calculate flags
        $flagVeryShort = $durationSec < 15 * 60;
        $flagLongRun = $durationSec > 90 * 60;

        // UPSERT to training_signals_v1
        DB::table('training_signals_v1')->updateOrInsert(
            ['workout_id' => $workoutId],
            [
                'duration_sec' => $durationSec,
                'distance_m' => $distanceM,
                'avg_pace_sec_per_km' => $avgPaceSecPerKm,
                'duration_bucket' => $durationBucket,
                'flag_very_short' => $flagVeryShort,
                'flag_long_run' => $flagLongRun,
                'generated_at' => now(),
            ]
        );
    }

    private function getWorkoutDt(array $summary, Carbon $createdAt): Carbon
    {
        $startTimeIso = $summary['startTimeIso'] ?? null;
        if (is_string($startTimeIso)) {
            try {
                return Carbon::parse($startTimeIso)->utc();
            } catch (\Throwable) {
                // fallback below
            }
        }

        return $createdAt->copy()->utc();
    }

    /**
     * Load priority (M2 beyond minimum):
     *   1) Weighted time-in-zone from intensity buckets (preferred, TRIMP-like minutes).
     *   2) Numeric intensity score (legacy / seeded test data).
     *   3) Duration/60 proxy (last-resort fallback).
     *
     * @param  array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float,totalSec:float}|null  $buckets
     */
    private function extractLoadValue(array $summary, ?array $buckets = null): float
    {
        $buckets ??= $this->extractBuckets($summary);
        $zoneSec = $buckets['z1Sec'] + $buckets['z2Sec'] + $buckets['z3Sec'] + $buckets['z4Sec'] + $buckets['z5Sec'];
        if ($zoneSec > 0) {
            $weighted = $buckets['z1Sec'] * 1
                + $buckets['z2Sec'] * 2
                + $buckets['z3Sec'] * 3
                + $buckets['z4Sec'] * 4
                + $buckets['z5Sec'] * 5;

            return round($weighted / 60.0, 2);
        }

        $intensity = $summary['intensity'] ?? null;
        if (is_numeric($intensity)) {
            return (float) $intensity;
        }

        $durationSec = $this->toNumberOrZero($summary['trimmed']['durationSec'] ?? $summary['original']['durationSec'] ?? $summary['durationSec'] ?? null);
        if ($durationSec > 0) {
            return round($durationSec / 60.0, 2);
        }

        return 0.0;
    }

    /**
     * @return array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float,totalSec:float}
     */
    private function extractBuckets(array $summary): array
    {
        $bucketsRaw = $summary['intensityBuckets'] ?? null;
        if (! is_array($bucketsRaw) && isset($summary['intensity']) && is_array($summary['intensity'])) {
            $bucketsRaw = $summary['intensity'];
        }

        if (! is_array($bucketsRaw)) {
            return $this->emptyBuckets();
        }

        $z1 = $this->toNumberOrZero($bucketsRaw['z1Sec'] ?? null);
        $z2 = $this->toNumberOrZero($bucketsRaw['z2Sec'] ?? null);
        $z3 = $this->toNumberOrZero($bucketsRaw['z3Sec'] ?? null);
        $z4 = $this->toNumberOrZero($bucketsRaw['z4Sec'] ?? null);
        $z5 = $this->toNumberOrZero($bucketsRaw['z5Sec'] ?? null);
        $total = $this->toNumberOrZero($bucketsRaw['totalSec'] ?? null);
        if ($total <= 0) {
            $total = $z1 + $z2 + $z3 + $z4 + $z5;
        }

        return [
            'z1Sec' => $z1,
            'z2Sec' => $z2,
            'z3Sec' => $z3,
            'z4Sec' => $z4,
            'z5Sec' => $z5,
            'totalSec' => $total,
        ];
    }

    /**
     * @return array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float,totalSec:float}
     */
    private function emptyBuckets(): array
    {
        return [
            'z1Sec' => 0.0,
            'z2Sec' => 0.0,
            'z3Sec' => 0.0,
            'z4Sec' => 0.0,
            'z5Sec' => 0.0,
            'totalSec' => 0.0,
        ];
    }

    /**
     * @param  array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float,totalSec:float}  $a
     * @param  array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float,totalSec:float}  $b
     * @return array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float,totalSec:float}
     */
    private function addBuckets(array $a, array $b): array
    {
        return [
            'z1Sec' => $a['z1Sec'] + $b['z1Sec'],
            'z2Sec' => $a['z2Sec'] + $b['z2Sec'],
            'z3Sec' => $a['z3Sec'] + $b['z3Sec'],
            'z4Sec' => $a['z4Sec'] + $b['z4Sec'],
            'z5Sec' => $a['z5Sec'] + $b['z5Sec'],
            'totalSec' => $a['totalSec'] + $b['totalSec'],
        ];
    }

    private function toNumberOrZero(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<string,mixed>|null  $lastWorkout
     * @return array{missedKeyWorkout:bool,harderThanPlanned:bool,easierThanPlannedStreak:int,controlStartRecent:bool}
     */
    private function buildAdaptationSignals(int $userId, array $filteredRows, Carbon $windowEnd, ?array $lastWorkout): array
    {
        $missedKeyWorkout = $this->hasMissedKeyWorkout($userId, $windowEnd);
        $workoutIds = array_values(array_map(
            fn (array $row) => (int) ($row['id'] ?? 0),
            array_filter($filteredRows, fn (array $row) => isset($row['id']))
        ));
        $complianceV1ByWorkout = DB::table('plan_compliance_v1')
            ->whereIn('workout_id', $workoutIds ?: [0])
            ->get(['workout_id', 'status', 'duration_ratio'])
            ->keyBy('workout_id');
        $complianceV2ByWorkout = DB::table('plan_compliance_v2')
            ->whereIn('workout_id', $workoutIds ?: [0])
            ->get(['workout_id', 'flag_easy_became_z5'])
            ->keyBy('workout_id');

        usort($filteredRows, fn (array $a, array $b) => $b['workoutDt']->getTimestamp() <=> $a['workoutDt']->getTimestamp());

        $harderThanPlanned = false;
        $easierStreak = 0;
        foreach ($filteredRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $v1 = $complianceV1ByWorkout[$id] ?? null;
            $v2 = $complianceV2ByWorkout[$id] ?? null;
            $v1Status = $v1->status ?? null;
            $v1Ratio = $v1->duration_ratio ?? null;
            $v2EasyBecameZ5 = $v2->flag_easy_became_z5 ?? null;

            $isHard = ($v1Status === 'MAJOR_DEVIATION' && is_numeric($v1Ratio) && (float) $v1Ratio > 1.15)
                || ((bool) $v2EasyBecameZ5);
            if ($isHard) {
                $harderThanPlanned = true;
            }

            $isEasy = is_numeric($v1Ratio) && (float) $v1Ratio < 0.85;
            if ($isEasy) {
                $easierStreak++;
            } else {
                break;
            }
        }

        $controlStartRecent = false;
        if (is_array($lastWorkout)) {
            $lastDt = $lastWorkout['workoutDt'] ?? null;
            if ($lastDt instanceof Carbon) {
                $within10d = $windowEnd->diffInDays($lastDt) <= 10;
                $isRace = strtolower((string) ($lastWorkout['kind'] ?? '')) === 'race';
                $raceMeta = is_array($lastWorkout['raceMeta'] ?? null) ? $lastWorkout['raceMeta'] : [];
                $isControl = ($raceMeta['controlStart'] ?? false) === true
                    || strtolower((string) ($raceMeta['eventType'] ?? '')) === 'control';
                $controlStartRecent = $within10d && ($isRace || $isControl);
            }
        }

        return [
            'missedKeyWorkout' => $missedKeyWorkout,
            'harderThanPlanned' => $harderThanPlanned,
            'easierThanPlannedStreak' => $easierStreak,
            'controlStartRecent' => $controlStartRecent,
        ];
    }

    private function hasMissedKeyWorkout(int $userId, Carbon $windowEnd): bool
    {
        $snapshot = DB::table('plan_snapshots')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first(['snapshot_json']);
        if (! $snapshot) {
            return false;
        }

        $decoded = json_decode((string) $snapshot->snapshot_json, true);
        if (! is_array($decoded)) {
            return false;
        }
        $items = $decoded['items'] ?? $decoded;
        if (! is_array($items) || ! array_is_list($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['startTimeIso'])) {
                continue;
            }
            try {
                $plannedDt = Carbon::parse((string) $item['startTimeIso'])->utc();
            } catch (\Throwable) {
                continue;
            }
            if ($plannedDt->greaterThan($windowEnd) || $plannedDt->lessThan($windowEnd->copy()->subDays(3))) {
                continue;
            }

            $matched = Workout::query()
                ->where('user_id', $userId)
                ->get(['summary', 'created_at'])
                ->contains(function (Workout $workout) use ($plannedDt): bool {
                    $summary = is_array($workout->summary) ? $workout->summary : [];
                    $dt = $this->getWorkoutDt($summary, $workout->created_at);

                    return abs($dt->diffInSeconds($plannedDt)) <= (12 * 60 * 60);
                });

            if (! $matched) {
                return true;
            }
        }

        return false;
    }
}
