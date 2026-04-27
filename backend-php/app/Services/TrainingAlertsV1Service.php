<?php

namespace App\Services;

use App\Models\Workout;
use App\Services\Analysis\UserTrainingAnalysisService;
use App\Services\Analysis\WorkoutFactsAggregator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M3/M4 beyond current scope — Etap F.
 *
 * Dodano:
 *  - family           : safety | compliance | trend | data_quality
 *  - confidence       : low | medium | high
 *  - explanation_code : klucz wyjaśnienia (słownikowy)
 *  - week_id          : per-week trend alerts (workout_id wtedy null)
 *
 * Nowe alerty trendowe (upsertWeeklyAlerts):
 *  - UNDER_RECOVERY_TREND
 *  - EXECUTION_DRIFT
 *  - STALE_MISSED_CAPABILITY
 *  - EXCESSIVE_DENSITY_TREND
 *  - BLOCK_GOAL_NOT_MET
 *  - PAIN_WITH_LOAD_CONFLICT
 */
class TrainingAlertsV1Service
{
    public function __construct(
        private readonly ?PlanMemoryService $planMemoryService = null,
        private readonly ?UserTrainingAnalysisService $analysisService = null,
    ) {}

    public function upsertForWorkout(int $workoutId): void
    {
        $workout = Workout::find($workoutId);
        if (! $workout) {
            return;
        }

        $complianceV1 = DB::table('plan_compliance_v1')
            ->where('workout_id', $workoutId)
            ->first();

        $complianceV2 = DB::table('plan_compliance_v2')
            ->where('workout_id', $workoutId)
            ->first();

        $signalsV2 = DB::table('training_signals_v2')
            ->where('workout_id', $workoutId)
            ->first();

        $activeAlerts = [];

        // LOAD_SPIKE (WARNING, safety)
        $loadSpike = $this->resolveLoadSpikeForWorkout($workout);
        if ($loadSpike['isSpike']) {
            $activeAlerts[] = [
                'code' => 'LOAD_SPIKE',
                'severity' => 'WARNING',
                'family' => 'safety',
                'confidence' => 'high',
                'explanation_code' => 'load_spike_7d',
                'payload_json' => json_encode([
                    'current7dLoad' => $loadSpike['current7dLoad'],
                    'previous7dLoad' => $loadSpike['previous7dLoad'],
                    'rampRatio' => $loadSpike['rampRatio'],
                    'thresholdRatio' => $loadSpike['thresholdRatio'],
                    'source' => $loadSpike['source'],
                    'analysisComputedAt' => $loadSpike['analysisComputedAt'] ?? null,
                ]),
            ];
        }

        if ($this->hasMissedKeyWorkout($workout->user_id, $workout)) {
            $activeAlerts[] = [
                'code' => 'MISSED_KEY_WORKOUT',
                'severity' => 'WARNING',
                'family' => 'compliance',
                'confidence' => 'medium',
                'explanation_code' => 'missed_key_workout',
                'payload_json' => json_encode(['reason' => 'planned_key_session_not_completed']),
            ];
        }

        $easierStreak = $this->easierThanPlannedStreak($workout->user_id);
        if ($easierStreak >= 2) {
            $activeAlerts[] = [
                'code' => 'EASIER_THAN_PLANNED_STREAK',
                'severity' => 'INFO',
                'family' => 'compliance',
                'confidence' => 'medium',
                'explanation_code' => 'easier_than_planned_streak',
                'payload_json' => json_encode(['streak' => $easierStreak]),
            ];
        }

        if (! $complianceV1 || $complianceV1->status === 'UNKNOWN') {
            $activeAlerts[] = [
                'code' => 'PLAN_MISSING',
                'severity' => 'INFO',
                'family' => 'data_quality',
                'confidence' => 'high',
                'explanation_code' => 'plan_missing',
                'payload_json' => json_encode(['reason' => 'no_plan_or_no_match']),
            ];
        }

        if ($complianceV1 &&
            $complianceV1->status === 'MAJOR_DEVIATION' &&
            $complianceV1->duration_ratio !== null &&
            $complianceV1->duration_ratio > 1.0) {
            $activeAlerts[] = [
                'code' => 'DURATION_MAJOR_OVERSHOOT',
                'severity' => 'CRITICAL',
                'family' => 'compliance',
                'confidence' => 'high',
                'explanation_code' => 'duration_major_overshoot',
                'payload_json' => json_encode([
                    'expectedDurationSec' => $complianceV1->expected_duration_sec,
                    'actualDurationSec' => $complianceV1->actual_duration_sec,
                    'deltaDurationSec' => $complianceV1->delta_duration_sec,
                    'durationRatio' => $complianceV1->duration_ratio,
                    'status' => $complianceV1->status,
                ]),
            ];
        }

        if ($complianceV1 &&
            $complianceV1->status === 'MAJOR_DEVIATION' &&
            $complianceV1->duration_ratio !== null &&
            $complianceV1->duration_ratio < 1.0) {
            $activeAlerts[] = [
                'code' => 'DURATION_MAJOR_UNDERSHOOT',
                'severity' => 'WARNING',
                'family' => 'compliance',
                'confidence' => 'high',
                'explanation_code' => 'duration_major_undershoot',
                'payload_json' => json_encode([
                    'expectedDurationSec' => $complianceV1->expected_duration_sec,
                    'actualDurationSec' => $complianceV1->actual_duration_sec,
                    'deltaDurationSec' => $complianceV1->delta_duration_sec,
                    'durationRatio' => $complianceV1->duration_ratio,
                    'status' => $complianceV1->status,
                ]),
            ];
        }

        if ($complianceV2 && (bool) ($complianceV2->flag_easy_became_z5 ?? false)) {
            $activeAlerts[] = [
                'code' => 'EASY_BECAME_Z5',
                'severity' => 'CRITICAL',
                'family' => 'safety',
                'confidence' => 'high',
                'explanation_code' => 'easy_became_z5',
                'payload_json' => json_encode([
                    'expectedHrZoneMin' => $complianceV2->expected_hr_zone_min,
                    'expectedHrZoneMax' => $complianceV2->expected_hr_zone_max,
                    'actualHrZ5Sec' => $complianceV2->actual_hr_z5_sec,
                    'highIntensityRatio' => $complianceV2->high_intensity_ratio,
                ]),
            ];
        }

        if (! $signalsV2 || ($signalsV2->hr_available ?? 0) == 0) {
            $activeAlerts[] = [
                'code' => 'HR_DATA_MISSING',
                'severity' => 'INFO',
                'family' => 'data_quality',
                'confidence' => 'high',
                'explanation_code' => 'hr_data_missing',
                'payload_json' => json_encode(['reason' => 'missing_hr']),
            ];
        }

        $activeCodes = array_column($activeAlerts, 'code');

        $existingAlerts = DB::table('training_alerts_v1')
            ->where('workout_id', $workoutId)
            ->pluck('code')
            ->toArray();

        $codesToDelete = array_diff($existingAlerts, $activeCodes);
        if (! empty($codesToDelete)) {
            DB::table('training_alerts_v1')
                ->where('workout_id', $workoutId)
                ->whereIn('code', $codesToDelete)
                ->delete();
        }

        foreach ($activeAlerts as $alert) {
            DB::table('training_alerts_v1')->updateOrInsert(
                [
                    'workout_id' => $workoutId,
                    'code' => $alert['code'],
                ],
                [
                    'severity' => $alert['severity'],
                    'family' => $alert['family'] ?? null,
                    'confidence' => $alert['confidence'] ?? 'medium',
                    'explanation_code' => $alert['explanation_code'] ?? null,
                    'week_id' => null,
                    'payload_json' => $alert['payload_json'],
                    'generated_at' => now(),
                ]
            );
        }
    }

    /**
     * Alerty trendowe per-week. Wymaga obecnego rekordu training_weeks dla tego tygodnia.
     */
    public function upsertWeeklyAlerts(int $userId, string $weekStartDate): void
    {
        $week = DB::table('training_weeks')
            ->where('user_id', $userId)
            ->where('week_start_date', $weekStartDate)
            ->first();
        if (! $week) {
            return;
        }
        $weekId = (int) $week->id;

        $memory = $this->planMemoryService;
        $recentWeeks = $memory !== null
            ? $memory->getRecentWeeks($userId, 6)
            : $this->getRecentWeeksRaw($userId, 6);

        $activeAlerts = [];

        // UNDER_RECOVERY_TREND
        $underRecoveryWeeks = 0;
        foreach (array_slice($recentWeeks, 0, 3) as $w) {
            $isDecrease = ($w['load_direction'] ?? null) === 'decrease';
            $plannedQ = $w['planned_quality_count'] ?? 0;
            $actualQ = $w['actual_quality_count'] ?? 0;
            if ($isDecrease && $actualQ !== null && $plannedQ !== null && (int) $actualQ < (int) $plannedQ) {
                $underRecoveryWeeks++;
            }
        }
        if ($underRecoveryWeeks >= 2) {
            $activeAlerts[] = [
                'code' => 'UNDER_RECOVERY_TREND',
                'severity' => 'WARNING',
                'family' => 'trend',
                'confidence' => 'medium',
                'explanation_code' => 'under_recovery_trend',
                'payload_json' => json_encode(['underRecoveryWeeks' => $underRecoveryWeeks]),
            ];
        }

        // EXECUTION_DRIFT: 3 ostatnie tygodnie ratio < 0.75
        $driftCount = 0;
        foreach (array_slice($recentWeeks, 0, 3) as $w) {
            $planned = (int) ($w['planned_total_min'] ?? 0);
            $actual = (int) ($w['actual_total_min'] ?? 0);
            if ($planned > 0 && ($actual / $planned) < 0.75) {
                $driftCount++;
            }
        }
        if ($driftCount >= 3) {
            $activeAlerts[] = [
                'code' => 'EXECUTION_DRIFT',
                'severity' => 'WARNING',
                'family' => 'compliance',
                'confidence' => 'high',
                'explanation_code' => 'execution_drift',
                'payload_json' => json_encode(['driftWeeks' => $driftCount]),
            ];
        }

        // STALE_MISSED_CAPABILITY: focus nie realizowany przez 3+ tyg
        $missedCapability = 0;
        $targetFocus = (string) ($week->key_capability_focus ?? '');
        if ($targetFocus !== '') {
            foreach (array_slice($recentWeeks, 0, 3) as $w) {
                $wFocus = (string) ($w['key_capability_focus'] ?? '');
                $actualQ = (int) ($w['actual_quality_count'] ?? 0);
                if ($wFocus === $targetFocus && $actualQ === 0) {
                    $missedCapability++;
                }
            }
        }
        if ($missedCapability >= 3) {
            $activeAlerts[] = [
                'code' => 'STALE_MISSED_CAPABILITY',
                'severity' => 'INFO',
                'family' => 'trend',
                'confidence' => 'medium',
                'explanation_code' => 'stale_missed_capability',
                'payload_json' => json_encode([
                    'focus' => $targetFocus,
                    'weeksMissed' => $missedCapability,
                ]),
            ];
        }

        // EXCESSIVE_DENSITY_TREND: 2+ tygodni actual_quality_count >= 3
        $densityStreak = 0;
        foreach (array_slice($recentWeeks, 0, 3) as $w) {
            $qc = (int) ($w['actual_quality_count'] ?? 0);
            if ($qc >= 3) {
                $densityStreak++;

                continue;
            }
            break;
        }
        if ($densityStreak >= 2) {
            $activeAlerts[] = [
                'code' => 'EXCESSIVE_DENSITY_TREND',
                'severity' => 'WARNING',
                'family' => 'safety',
                'confidence' => 'medium',
                'explanation_code' => 'excessive_density_trend',
                'payload_json' => json_encode(['densityStreak' => $densityStreak]),
            ];
        }

        // BLOCK_GOAL_NOT_MET: blok kończy się + >60% tygodni bloku bez goal_met
        $currentRole = (string) ($week->week_role ?? '');
        $currentBlock = (string) ($week->block_type ?? '');
        if (($currentRole === 'recovery' || $currentRole === 'taper') && $currentBlock !== '') {
            $blockWeeks = [];
            foreach ($recentWeeks as $w) {
                if (($w['block_type'] ?? null) === $currentBlock) {
                    $blockWeeks[] = $w;

                    continue;
                }
                if (! empty($blockWeeks)) {
                    break;
                }
            }
            $countBlock = count($blockWeeks);
            $notMet = 0;
            foreach ($blockWeeks as $w) {
                if (($w['goal_met'] ?? null) === false || ($w['goal_met'] ?? null) === 0) {
                    $notMet++;
                }
            }
            if ($countBlock >= 2 && ($notMet / $countBlock) > 0.60) {
                $activeAlerts[] = [
                    'code' => 'BLOCK_GOAL_NOT_MET',
                    'severity' => 'INFO',
                    'family' => 'compliance',
                    'confidence' => 'low',
                    'explanation_code' => 'block_goal_not_met',
                    'payload_json' => json_encode([
                        'blockType' => $currentBlock,
                        'weeksNotMet' => $notMet,
                        'weeksInBlock' => $countBlock,
                    ]),
                ];
            }
        }

        // PAIN_WITH_LOAD_CONFLICT: profile.hasCurrentPain + planned_quality_count > 0
        $profile = DB::table('user_profiles')->where('user_id', $userId)->first(['has_current_pain']);
        $hasCurrentPain = (bool) ($profile->has_current_pain ?? false);
        $plannedQ = (int) ($week->planned_quality_count ?? 0);
        if ($hasCurrentPain && $plannedQ > 0) {
            $activeAlerts[] = [
                'code' => 'PAIN_WITH_LOAD_CONFLICT',
                'severity' => 'CRITICAL',
                'family' => 'safety',
                'confidence' => 'high',
                'explanation_code' => 'pain_with_load_conflict',
                'payload_json' => json_encode(['plannedQualityCount' => $plannedQ]),
            ];
        }

        $activeCodes = array_column($activeAlerts, 'code');

        $existingWeeklyAlerts = DB::table('training_alerts_v1')
            ->where('week_id', $weekId)
            ->whereNull('workout_id')
            ->pluck('code')
            ->toArray();

        $codesToDelete = array_diff($existingWeeklyAlerts, $activeCodes);
        if (! empty($codesToDelete)) {
            DB::table('training_alerts_v1')
                ->where('week_id', $weekId)
                ->whereNull('workout_id')
                ->whereIn('code', $codesToDelete)
                ->delete();
        }

        foreach ($activeAlerts as $alert) {
            $existing = DB::table('training_alerts_v1')
                ->where('week_id', $weekId)
                ->whereNull('workout_id')
                ->where('code', $alert['code'])
                ->first(['id']);
            $payload = [
                'workout_id' => null,
                'week_id' => $weekId,
                'code' => $alert['code'],
                'severity' => $alert['severity'],
                'family' => $alert['family'] ?? null,
                'confidence' => $alert['confidence'] ?? 'medium',
                'explanation_code' => $alert['explanation_code'] ?? null,
                'payload_json' => $alert['payload_json'],
                'generated_at' => now(),
            ];
            if ($existing) {
                DB::table('training_alerts_v1')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('training_alerts_v1')->insert($payload);
            }
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getRecentWeeksRaw(int $userId, int $count): array
    {
        return DB::table('training_weeks')
            ->where('user_id', $userId)
            ->orderByDesc('week_start_date')
            ->limit(max(1, $count))
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /**
     * @return array{isSpike:bool,current7dLoad:float,previous7dLoad:float,rampRatio:float|null,thresholdRatio:float,source:string,analysisComputedAt:string|null}
     */
    private function resolveLoadSpikeForWorkout(Workout $workout): array
    {
        try {
            $service = $this->analysisService ?? app(UserTrainingAnalysisService::class);
            $analysis = $service->analyze((int) $workout->user_id, 28)->toArray();
            $facts = is_array($analysis['facts'] ?? null) ? $analysis['facts'] : [];
            $codes = $this->codes($analysis['planImplications'] ?? []);

            $load7d = $this->numberOrZero($facts['overallFatigue7d'] ?? $facts['load7d'] ?? null);
            $load28d = $this->numberOrZero($facts['overallFatigue28d'] ?? $facts['load28d'] ?? null);
            $acwr = is_numeric($facts['acwrOverall'] ?? null)
                ? (float) $facts['acwrOverall']
                : (is_numeric($facts['acwr'] ?? null) ? (float) $facts['acwr'] : null);
            $baseline7d = $load28d > 0 ? round($load28d / 4.0, 2) : 0.0;
            $isSpike = (bool) ($facts['spikeLoad'] ?? false) || in_array('load_spike', $codes, true);

            return [
                'isSpike' => $isSpike,
                'current7dLoad' => round($load7d, 2),
                'previous7dLoad' => $baseline7d,
                'rampRatio' => $acwr !== null ? round($acwr, 3) : null,
                'thresholdRatio' => WorkoutFactsAggregator::SPIKE_ACWR_THRESHOLD,
                'source' => 'user_training_analysis',
                'analysisComputedAt' => is_string($analysis['computedAt'] ?? null) ? $analysis['computedAt'] : null,
            ];
        } catch (\Throwable) {
            return $this->calculateLoadSpikeForWorkout($workout);
        }
    }

    /**
     * @deprecated F7 keeps this only as a best-effort fallback for legacy import paths.
     *
     * @return array{isSpike:bool,current7dLoad:float,previous7dLoad:float,rampRatio:float|null,thresholdRatio:float,source:string,analysisComputedAt:null}
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
            'thresholdRatio' => 1.3,
            'source' => 'legacy_training_signals',
            'analysisComputedAt' => null,
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

        $durationSec = $summary['trimmed']['durationSec'] ?? $summary['original']['durationSec'] ?? $summary['durationSec'] ?? null;
        if (is_numeric($durationSec) && (float) $durationSec > 0) {
            return (float) $durationSec / 60.0;
        }

        return 0.0;
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

    private function hasMissedKeyWorkout(int $userId, Workout $anchorWorkout): bool
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

        $anchorSummary = is_array($anchorWorkout->summary) ? $anchorWorkout->summary : [];
        $anchorDt = $this->resolveWorkoutDt($anchorSummary, $anchorWorkout->created_at);
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['startTimeIso'])) {
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

            if (! $matched) {
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
