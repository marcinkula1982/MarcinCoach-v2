<?php

namespace App\Services;

use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TrainingSignalsService
{
    public function getSignalsForUser(int $userId, int $days = 28): array
    {
        $workouts = Workout::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get(['id', 'created_at', 'summary']);

        $rows = $workouts->map(function (Workout $workout) {
            $summary = is_array($workout->summary) ? $workout->summary : [];
            $workoutDt = $this->getWorkoutDt($summary, $workout->created_at);
            $loadValue = $this->extractLoadValue($summary);
            $buckets = $this->extractBuckets($summary);

            return [
                'id' => $workout->id,
                'workoutDt' => $workoutDt,
                'loadValue' => $loadValue,
                'buckets' => $buckets,
            ];
        })->values();

        if ($rows->isEmpty()) {
            $windowEnd = now()->utc();
            $windowStart = $windowEnd->copy()->subSeconds($days * 24 * 60 * 60);
        } else {
            $windowEndTs = $rows->max(fn (array $row) => $row['workoutDt']->getTimestamp());
            $windowEnd = Carbon::createFromTimestampUTC((int) $windowEndTs);
            $windowStart = $windowEnd->copy()->subSeconds($days * 24 * 60 * 60);
        }

        $filtered = $rows
            ->filter(fn (array $row) => $row['workoutDt']->greaterThanOrEqualTo($windowStart) && $row['workoutDt']->lessThanOrEqualTo($windowEnd))
            ->values();

        $weeklyFrom = $windowEnd->copy()->subDays(7);
        $weeklyLoad = $filtered
            ->filter(fn (array $row) => $row['workoutDt']->greaterThan($weeklyFrom))
            ->sum('loadValue');
        $rolling4wLoad = $filtered->sum('loadValue');

        $bucketTotals = $this->emptyBuckets();
        foreach ($filtered as $row) {
            $bucketTotals = $this->addBuckets($bucketTotals, $row['buckets']);
        }

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
            'totalWorkouts' => $filtered->count(),
        ];
    }

    public function upsertForWorkout(int $workoutId): void
    {
        $workout = Workout::find($workoutId);
        if (!$workout) {
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

    private function extractLoadValue(array $summary): float
    {
        $intensity = $summary['intensity'] ?? null;
        if (is_numeric($intensity)) {
            return (float) $intensity;
        }

        return 0.0;
    }

    /**
     * @return array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float,totalSec:float}
     */
    private function extractBuckets(array $summary): array
    {
        $bucketsRaw = $summary['intensityBuckets'] ?? null;
        if (!is_array($bucketsRaw) && isset($summary['intensity']) && is_array($summary['intensity'])) {
            $bucketsRaw = $summary['intensity'];
        }

        if (!is_array($bucketsRaw)) {
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
     * @param array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float,totalSec:float} $a
     * @param array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float,totalSec:float} $b
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
}


