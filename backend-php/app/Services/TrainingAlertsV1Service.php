<?php

namespace App\Services;

use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TrainingAlertsV1Service
{
    public function upsertForWorkout(int $workoutId): void
    {
        $workout = Workout::find($workoutId);
        if (!$workout) {
            return;
        }

        // Pobierz rekordy z tabel źródłowych
        $complianceV1 = DB::table('plan_compliance_v1')
            ->where('workout_id', $workoutId)
            ->first();

        $complianceV2 = DB::table('plan_compliance_v2')
            ->where('workout_id', $workoutId)
            ->first();

        $signalsV2 = DB::table('training_signals_v2')
            ->where('workout_id', $workoutId)
            ->first();

        // Zbuduj listę aktywnych alertów
        $activeAlerts = [];

        // PLAN_MISSING (INFO)
        if (!$complianceV1 || $complianceV1->status === 'UNKNOWN') {
            $activeAlerts[] = [
                'code' => 'PLAN_MISSING',
                'severity' => 'INFO',
                'payload_json' => json_encode(['reason' => 'no_plan_or_no_match']),
            ];
        }

        // DURATION_MAJOR_OVERSHOOT (CRITICAL)
        if ($complianceV1 && 
            $complianceV1->status === 'MAJOR_DEVIATION' && 
            $complianceV1->duration_ratio !== null && 
            $complianceV1->duration_ratio > 1.0) {
            $activeAlerts[] = [
                'code' => 'DURATION_MAJOR_OVERSHOOT',
                'severity' => 'CRITICAL',
                'payload_json' => json_encode([
                    'expectedDurationSec' => $complianceV1->expected_duration_sec,
                    'actualDurationSec' => $complianceV1->actual_duration_sec,
                    'deltaDurationSec' => $complianceV1->delta_duration_sec,
                    'durationRatio' => $complianceV1->duration_ratio,
                    'status' => $complianceV1->status,
                ]),
            ];
        }

        // DURATION_MAJOR_UNDERSHOOT (WARNING)
        if ($complianceV1 && 
            $complianceV1->status === 'MAJOR_DEVIATION' && 
            $complianceV1->duration_ratio !== null && 
            $complianceV1->duration_ratio < 1.0) {
            $activeAlerts[] = [
                'code' => 'DURATION_MAJOR_UNDERSHOOT',
                'severity' => 'WARNING',
                'payload_json' => json_encode([
                    'expectedDurationSec' => $complianceV1->expected_duration_sec,
                    'actualDurationSec' => $complianceV1->actual_duration_sec,
                    'deltaDurationSec' => $complianceV1->delta_duration_sec,
                    'durationRatio' => $complianceV1->duration_ratio,
                    'status' => $complianceV1->status,
                ]),
            ];
        }

        // EASY_BECAME_Z5 (CRITICAL)
        if ($complianceV2 && (bool) ($complianceV2->flag_easy_became_z5 ?? false)) {
            $activeAlerts[] = [
                'code' => 'EASY_BECAME_Z5',
                'severity' => 'CRITICAL',
                'payload_json' => json_encode([
                    'expectedHrZoneMin' => $complianceV2->expected_hr_zone_min,
                    'expectedHrZoneMax' => $complianceV2->expected_hr_zone_max,
                    'actualHrZ5Sec' => $complianceV2->actual_hr_z5_sec,
                    'highIntensityRatio' => $complianceV2->high_intensity_ratio,
                ]),
            ];
        }

        // HR_DATA_MISSING (INFO)
        if (!$signalsV2 || ($signalsV2->hr_available ?? 0) == 0) {
            $activeAlerts[] = [
                'code' => 'HR_DATA_MISSING',
                'severity' => 'INFO',
                'payload_json' => json_encode(['reason' => 'missing_hr']),
            ];
        }

        // Pobierz listę kodów aktywnych alertów
        $activeCodes = array_column($activeAlerts, 'code');

        // Pobierz istniejące alerty dla tego workoutu
        $existingAlerts = DB::table('training_alerts_v1')
            ->where('workout_id', $workoutId)
            ->pluck('code')
            ->toArray();

        // Cleanup: usuń alerty, które przestały być aktywne
        $codesToDelete = array_diff($existingAlerts, $activeCodes);
        if (!empty($codesToDelete)) {
            DB::table('training_alerts_v1')
                ->where('workout_id', $workoutId)
                ->whereIn('code', $codesToDelete)
                ->delete();
        }

        // UPSERT aktywnych alertów
        foreach ($activeAlerts as $alert) {
            DB::table('training_alerts_v1')->updateOrInsert(
                [
                    'workout_id' => $workoutId,
                    'code' => $alert['code'],
                ],
                [
                    'severity' => $alert['severity'],
                    'payload_json' => $alert['payload_json'],
                    'generated_at' => now(),
                ]
            );
        }
    }
}

