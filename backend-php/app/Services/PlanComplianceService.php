<?php

namespace App\Services;

use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PlanComplianceService
{
    public function upsertForWorkout(int $workoutId): void
    {
        $workout = Workout::find($workoutId);
        if (!$workout) {
            return;
        }

        $summary = $workout->summary ?? [];
        $actualDurationSec = $summary['durationSec'] ?? 0;
        $workoutStartTimeIso = $summary['startTimeIso'] ?? null;

        if (!$workoutStartTimeIso) {
            // No start time, cannot match to plan
            $this->saveCompliance($workoutId, $actualDurationSec, null, null, null, 'UNKNOWN', false, false);
            return;
        }

        // Parse workout start time as UTC
        try {
            $workoutStartTime = Carbon::parse($workoutStartTimeIso)->utc();
        } catch (\Exception $e) {
            $this->saveCompliance($workoutId, $actualDurationSec, null, null, null, 'UNKNOWN', false, false);
            return;
        }

        // Find matching plan_snapshot by time window
        $snapshot = DB::table('plan_snapshots')
            ->where('user_id', $workout->user_id)
            ->where('window_start_iso', '<=', $workoutStartTimeIso)
            ->where('window_end_iso', '>=', $workoutStartTimeIso)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$snapshot) {
            // No matching snapshot
            $this->saveCompliance($workoutId, $actualDurationSec, null, null, null, 'UNKNOWN', false, false);
            return;
        }

        // Parse snapshot_json
        $snapshotJson = json_decode($snapshot->snapshot_json, true);
        if (!is_array($snapshotJson)) {
            $this->saveCompliance($workoutId, $actualDurationSec, null, null, null, 'UNKNOWN', false, false);
            return;
        }

        // Extract planned items: support both new format {"items":[...]} and old format [...]
        $plannedItems = $snapshotJson['items'] ?? $snapshotJson;
        if (!is_array($plannedItems) || !array_is_list($plannedItems)) {
            $this->saveCompliance($workoutId, $actualDurationSec, null, null, null, 'UNKNOWN', false, false);
            return;
        }

        // Find closest planned item by time difference (12h tolerance)
        $closestPlanned = null;
        $minTimeDiff = null;
        $toleranceSeconds = 12 * 60 * 60; // 12 hours

        foreach ($plannedItems as $plannedItem) {
            if (!isset($plannedItem['startTimeIso'])) {
                continue;
            }

            try {
                $plannedStartTime = Carbon::parse($plannedItem['startTimeIso'])->utc();
                $timeDiff = abs($workoutStartTime->diffInSeconds($plannedStartTime));

                if ($timeDiff <= $toleranceSeconds && ($minTimeDiff === null || $timeDiff < $minTimeDiff)) {
                    $minTimeDiff = $timeDiff;
                    $closestPlanned = $plannedItem;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        if (!$closestPlanned || $minTimeDiff === null) {
            // No matching planned item within tolerance
            $this->saveCompliance($workoutId, $actualDurationSec, null, null, null, 'UNKNOWN', false, false);
            return;
        }

        // Extract expected values
        $expectedDurationSec = $closestPlanned['expectedDurationSec'] ?? null;
        if ($expectedDurationSec === null) {
            // No expected duration
            $this->saveCompliance($workoutId, $actualDurationSec, null, null, null, 'UNKNOWN', false, false);
            return;
        }

        // Calculate compliance metrics
        $deltaDurationSec = $actualDurationSec - $expectedDurationSec;
        $durationRatio = $actualDurationSec / $expectedDurationSec;

        // Determine status based on ratio thresholds
        $status = 'UNKNOWN';
        if ($durationRatio >= 0.85 && $durationRatio <= 1.15) {
            $status = 'OK';
        } elseif (($durationRatio >= 0.70 && $durationRatio < 0.85) || ($durationRatio > 1.15 && $durationRatio <= 1.30)) {
            $status = 'MINOR_DEVIATION';
        } else {
            $status = 'MAJOR_DEVIATION';
        }

        // Calculate flags based on ratio
        $flagOvershootDuration = $durationRatio > 1.0;
        $flagUndershootDuration = $durationRatio < 1.0;

        // Save compliance
        $this->saveCompliance(
            $workoutId,
            $actualDurationSec,
            $expectedDurationSec,
            $deltaDurationSec,
            $durationRatio,
            $status,
            $flagOvershootDuration,
            $flagUndershootDuration
        );
    }

    private function saveCompliance(
        int $workoutId,
        int $actualDurationSec,
        ?int $expectedDurationSec,
        ?int $deltaDurationSec,
        ?float $durationRatio,
        string $status,
        bool $flagOvershootDuration,
        bool $flagUndershootDuration
    ): void {
        DB::table('plan_compliance_v1')->updateOrInsert(
            ['workout_id' => $workoutId],
            [
                'expected_duration_sec' => $expectedDurationSec,
                'actual_duration_sec' => $actualDurationSec,
                'delta_duration_sec' => $deltaDurationSec,
                'duration_ratio' => $durationRatio,
                'status' => $status,
                'flag_overshoot_duration' => $flagOvershootDuration,
                'flag_undershoot_duration' => $flagUndershootDuration,
                'generated_at' => now(),
            ]
        );
    }
}

