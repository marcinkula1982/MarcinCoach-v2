<?php

namespace App\Services;

use App\Models\Workout;
use Illuminate\Support\Facades\DB;

class TrainingSignalsService
{
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
}


