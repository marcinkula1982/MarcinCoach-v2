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

        // LOAD_SPIKE (WARNING)
        $loadSpike = $this->calculateLoadSpikeForWorkout($workout);
        if ($loadSpike['isSpike']) {
            $activeAlerts[] = [
                'code' => 'LOAD_SPIKE',
                'severity' => 'WARNING',
                'payload_json' => json_encode([
                    'current7dLoad' => $loadSpike['current7dLoad'],
                    'previous7dLoad' => $loadSpike['previous7dLoad'],
                    'rampRatio' => $loadSpike['rampRatio'],
                    'thresholdRatio' => 1.3,
                ]),
            ];
        }

        if ($this->hasMissedKeyWorkout($workout->user_id, $workout)) {
            $activeAlerts[] = [
                'code' => 'MISSED_KEY_WORKOUT',
                'severity' => 'WARNING',
                'payload_json' => json_encode(['reason' => 'planned_key_session_not_completed']),
            ];
        }

        $easierStreak = $this->easierThanPlannedStreak($workout->user_id);
        if ($easierStreak >= 2) {
            $activeAlerts[] = [
                'code' => 'EASIER_THAN_PLANNED_STREAK',
                'severity' => 'INFO',
                'payload_json' => json_encode(['streak' => $easierStreak]),
            ];
        }

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

    /**
     * @return array{isSpike:bool,current7dLoad:float,previous7dLoad:float,rampRatio:float|null}
     */
    private function calculateLoadSpikeForWorkout(Workout $workout): array
    {
        $summary = is_array($workout->summary) ? $workout->summary : [];
        $workoutDt = $this->resolveWorkoutDt($summary, $workout->created_at);

        $currentFrom = $workoutDt->copy()->subDays(7);
        $previousFrom = $workoutDt->copy()->subDays(14);

        $rows = Workout::query()
            ->where('user_id', $workout->user_id)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get(['summary', 'created_at']);

        $current7dLoad = 0.0;
        $previous7dLoad = 0.0;

        foreach ($rows as $row) {
            $rowSummary = is_array($row->summary) ? $row->summary : [];
            $rowDt = $this->resolveWorkoutDt($rowSummary, $row->created_at);
            $load = $this->extractLoadValue($rowSummary);

            // Only workouts up to the analyzed workout timestamp.
            if ($rowDt->greaterThan($workoutDt)) {
                continue;
            }

            if ($rowDt->greaterThan($currentFrom) && $rowDt->lessThanOrEqualTo($workoutDt)) {
                $current7dLoad += $load;
                continue;
            }

            if ($rowDt->greaterThan($previousFrom) && $rowDt->lessThanOrEqualTo($currentFrom)) {
                $previous7dLoad += $load;
            }
        }

        $rampRatio = null;
        if ($previous7dLoad > 0) {
            $rampRatio = $current7dLoad / $previous7dLoad;
        }

        return [
            'isSpike' => $rampRatio !== null && $rampRatio > 1.3,
            'current7dLoad' => round($current7dLoad, 2),
            'previous7dLoad' => round($previous7dLoad, 2),
            'rampRatio' => $rampRatio !== null ? round($rampRatio, 3) : null,
        ];
    }

    private function resolveWorkoutDt(array $summary, $createdAt): Carbon
    {
        $startTimeIso = $summary['startTimeIso'] ?? null;
        if (is_string($startTimeIso) && $startTimeIso !== '') {
            try {
                return Carbon::parse($startTimeIso)->utc();
            } catch (\Throwable) {
                // fallback to created_at
            }
        }

        return Carbon::parse($createdAt)->utc();
    }

    private function extractLoadValue(array $summary): float
    {
        $intensity = $summary['intensity'] ?? null;
        if (is_numeric($intensity)) {
            return (float) $intensity;
        }

        // Fallback when intensity score is unavailable: duration minutes as low-fidelity load proxy.
        $durationSec = $summary['trimmed']['durationSec'] ?? $summary['original']['durationSec'] ?? $summary['durationSec'] ?? null;
        if (is_numeric($durationSec) && (float) $durationSec > 0) {
            return (float) $durationSec / 60.0;
        }

        return 0.0;
    }

    private function hasMissedKeyWorkout(int $userId, Workout $anchorWorkout): bool
    {
        $snapshot = DB::table('plan_snapshots')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first(['snapshot_json']);
        if (!$snapshot) {
            return false;
        }

        $decoded = json_decode((string) $snapshot->snapshot_json, true);
        if (!is_array($decoded)) {
            return false;
        }
        $items = $decoded['items'] ?? $decoded;
        if (!is_array($items) || !array_is_list($items)) {
            return false;
        }

        $anchorSummary = is_array($anchorWorkout->summary) ? $anchorWorkout->summary : [];
        $anchorDt = $this->resolveWorkoutDt($anchorSummary, $anchorWorkout->created_at);
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['startTimeIso'])) {
                continue;
            }
            try {
                $plannedDt = Carbon::parse((string) $item['startTimeIso'])->utc();
            } catch (\Throwable) {
                continue;
            }

            if ($plannedDt->greaterThan($anchorDt) || $plannedDt->lessThan($anchorDt->copy()->subDays(3))) {
                continue;
            }

            $matched = Workout::query()
                ->where('user_id', $userId)
                ->get(['summary', 'created_at'])
                ->contains(function (Workout $workout) use ($plannedDt): bool {
                    $summary = is_array($workout->summary) ? $workout->summary : [];
                    $dt = $this->resolveWorkoutDt($summary, $workout->created_at);
                    return abs($dt->diffInSeconds($plannedDt)) <= (12 * 60 * 60);
                });

            if (!$matched) {
                return true;
            }
        }

        return false;
    }

    private function easierThanPlannedStreak(int $userId): int
    {
        $rows = DB::table('workouts as w')
            ->join('plan_compliance_v1 as pc1', 'pc1.workout_id', '=', 'w.id')
            ->where('w.user_id', $userId)
            ->orderByDesc('w.created_at')
            ->limit(10)
            ->get(['pc1.duration_ratio']);

        $streak = 0;
        foreach ($rows as $row) {
            if (is_numeric($row->duration_ratio) && (float) $row->duration_ratio < 0.85) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }
}

