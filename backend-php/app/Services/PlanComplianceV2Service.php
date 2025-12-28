<?php

namespace App\Services;

use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PlanComplianceV2Service
{
    public function upsertForWorkout(int $workoutId): void
    {
        $workout = Workout::find($workoutId);
        if (!$workout) {
            return;
        }

        $summary = $workout->summary ?? [];
        $workoutStartTimeIso = $summary['startTimeIso'] ?? null;

        if (!$workoutStartTimeIso) {
            // No start time, cannot match to plan
            $this->saveCompliance($workoutId, null, null, null, null, null, null, null, null, null, 'UNKNOWN');
            return;
        }

        // Parse workout start time as UTC
        try {
            $workoutStartTime = Carbon::parse($workoutStartTimeIso)->utc();
        } catch (\Exception $e) {
            $this->saveCompliance($workoutId, null, null, null, null, null, null, null, null, null, 'UNKNOWN');
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
            $this->saveCompliance($workoutId, null, null, null, null, null, null, null, null, null, 'UNKNOWN');
            return;
        }

        // Parse snapshot_json
        $snapshotJson = json_decode($snapshot->snapshot_json, true);
        if (!is_array($snapshotJson)) {
            $this->saveCompliance($workoutId, null, null, null, null, null, null, null, null, null, 'UNKNOWN');
            return;
        }

        // Extract planned items: support both new format {"items":[...]} and old format [...]
        $plannedItems = $snapshotJson['items'] ?? $snapshotJson;
        if (!is_array($plannedItems) || !array_is_list($plannedItems)) {
            $this->saveCompliance($workoutId, null, null, null, null, null, null, null, null, null, 'UNKNOWN');
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
            $this->saveCompliance($workoutId, null, null, null, null, null, null, null, null, null, 'UNKNOWN');
            return;
        }

        // Extract expected HR zones
        $expectedHrZoneMin = $closestPlanned['expectedHrZoneMin'] ?? null;
        $expectedHrZoneMax = $closestPlanned['expectedHrZoneMax'] ?? null;

        if ($expectedHrZoneMin === null || $expectedHrZoneMax === null) {
            // No expected HR zones
            $this->saveCompliance($workoutId, null, null, null, null, null, null, null, null, null, 'UNKNOWN');
            return;
        }

        // Get training_signals_v2 data
        $signalsV2 = DB::table('training_signals_v2')
            ->where('workout_id', $workoutId)
            ->first();

        if (!$signalsV2 || !$signalsV2->hr_available) {
            // No signals v2 or HR not available
            $this->saveCompliance($workoutId, $expectedHrZoneMin, $expectedHrZoneMax, null, null, null, null, null, null, null, 'UNKNOWN');
            return;
        }

        // Extract actual HR zone times
        $actualHrZ1Sec = $signalsV2->hr_z1_sec;
        $actualHrZ2Sec = $signalsV2->hr_z2_sec;
        $actualHrZ3Sec = $signalsV2->hr_z3_sec;
        $actualHrZ4Sec = $signalsV2->hr_z4_sec;
        $actualHrZ5Sec = $signalsV2->hr_z5_sec;

        // Check if any zone times are null
        if ($actualHrZ1Sec === null || $actualHrZ2Sec === null || $actualHrZ3Sec === null || 
            $actualHrZ4Sec === null || $actualHrZ5Sec === null) {
            // Zone times not calculated (probably no HR zones in profile)
            $this->saveCompliance($workoutId, $expectedHrZoneMin, $expectedHrZoneMax, $actualHrZ1Sec, $actualHrZ2Sec, 
                $actualHrZ3Sec, $actualHrZ4Sec, $actualHrZ5Sec, null, null, 'UNKNOWN');
            return;
        }

        // Calculate total HR time and high intensity metrics
        $totalHrTime = $actualHrZ1Sec + $actualHrZ2Sec + $actualHrZ3Sec + $actualHrZ4Sec + $actualHrZ5Sec;
        $highIntensitySec = $actualHrZ4Sec + $actualHrZ5Sec;
        $highIntensityRatio = null;

        if ($totalHrTime > 0) {
            $highIntensityRatio = $highIntensitySec / $totalHrTime;
        } else {
            // No HR time recorded
            $this->saveCompliance($workoutId, $expectedHrZoneMin, $expectedHrZoneMax, $actualHrZ1Sec, $actualHrZ2Sec, 
                $actualHrZ3Sec, $actualHrZ4Sec, $actualHrZ5Sec, $highIntensitySec, $highIntensityRatio, 'UNKNOWN');
            return;
        }

        // Determine status based on expectedHrZoneMax and high_intensity_ratio
        $status = 'UNKNOWN';
        
        if ($expectedHrZoneMax <= 2) {
            // Easy workout (zones 1-2 expected)
            if ($highIntensityRatio <= 0.10) {
                $status = 'OK';
            } elseif ($highIntensityRatio <= 0.20) {
                $status = 'MINOR_DEVIATION';
            } else {
                $status = 'MAJOR_DEVIATION';
            }
        } elseif ($expectedHrZoneMax == 3) {
            // Moderate workout (zone 3 expected)
            if ($highIntensityRatio <= 0.20) {
                $status = 'OK';
            } elseif ($highIntensityRatio <= 0.30) {
                $status = 'MINOR_DEVIATION';
            } else {
                $status = 'MAJOR_DEVIATION';
            }
        } else {
            // High intensity workout (zones 4-5 expected) - always OK
            $status = 'OK';
        }

        // Check for easy_became_z5 flag: if easy plan (expectedHrZoneMax <= 2) and any time in Z5
        $flagEasyBecameZ5 = false;
        if ($expectedHrZoneMax <= 2 && $actualHrZ5Sec > 0) {
            $flagEasyBecameZ5 = true;
            $status = 'MAJOR_DEVIATION'; // Force MAJOR_DEVIATION regardless of ratio
        }

        // Save compliance
        $this->saveCompliance($workoutId, $expectedHrZoneMin, $expectedHrZoneMax, $actualHrZ1Sec, $actualHrZ2Sec, 
            $actualHrZ3Sec, $actualHrZ4Sec, $actualHrZ5Sec, $highIntensitySec, $highIntensityRatio, $status, $flagEasyBecameZ5);
    }

    /**
     * Save compliance to database.
     */
    private function saveCompliance(
        int $workoutId,
        ?int $expectedHrZoneMin,
        ?int $expectedHrZoneMax,
        ?int $actualHrZ1Sec,
        ?int $actualHrZ2Sec,
        ?int $actualHrZ3Sec,
        ?int $actualHrZ4Sec,
        ?int $actualHrZ5Sec,
        ?int $highIntensitySec,
        ?float $highIntensityRatio,
        string $status,
        bool $flagEasyBecameZ5 = false
    ): void {
        DB::table('plan_compliance_v2')->updateOrInsert(
            ['workout_id' => $workoutId],
            [
                'expected_hr_zone_min' => $expectedHrZoneMin,
                'expected_hr_zone_max' => $expectedHrZoneMax,
                'actual_hr_z1_sec' => $actualHrZ1Sec,
                'actual_hr_z2_sec' => $actualHrZ2Sec,
                'actual_hr_z3_sec' => $actualHrZ3Sec,
                'actual_hr_z4_sec' => $actualHrZ4Sec,
                'actual_hr_z5_sec' => $actualHrZ5Sec,
                'high_intensity_sec' => $highIntensitySec,
                'high_intensity_ratio' => $highIntensityRatio,
                'status' => $status,
                'flag_easy_became_z5' => $flagEasyBecameZ5,
                'generated_at' => now(),
            ]
        );
    }
}

