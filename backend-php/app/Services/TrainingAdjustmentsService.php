<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Port of backend/src/training-adjustments/training-adjustments.service.ts.
 * Pure logic, deterministic. No DB access.
 */
class TrainingAdjustmentsService
{
    private const SEVERITY_RANK = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
    ];

    /**
     * @param array{
     *   generatedAtIso:string,
     *   windowDays:int,
     *   signals:array<string,mixed>,
     *   profile:array<string,mixed>
     * } $context
     * @param array{warnings?:array<string,bool>}|null $feedbackSignals
     *
     * @return array{
     *   generatedAtIso:string,
     *   windowDays:int,
     *   adjustments:array<int,array<string,mixed>>
     * }
     */
    public function generate(array $context, ?array $feedbackSignals = null): array
    {
        $adjustments = [];

        $signals = $context['signals'] ?? [];
        $profile = $context['profile'] ?? [];
        $warnings = $feedbackSignals['warnings'] ?? [];

        // 1) fatigue flag
        if (($signals['flags']['fatigue'] ?? null) === true) {
            $adjustments[] = [
                'code' => 'reduce_load',
                'severity' => 'high',
                'rationale' => 'Detected fatigue flag in recent training window',
                'evidence' => [['key' => 'fatigue', 'value' => true]],
                'params' => ['reductionPct' => 25],
            ];
        }

        // 2) injury risk guard (return after break / post-race safety)
        if (($signals['flags']['injuryRisk'] ?? null) === true) {
            $adjustments[] = [
                'code' => 'recovery_focus',
                'severity' => 'high',
                'rationale' => 'Injury risk flag detected in recent training window',
                'evidence' => [['key' => 'injuryRisk', 'value' => true]],
                'params' => ['replaceHardSessionWithEasy' => true, 'longRunReductionPct' => 20],
            ];
        }

        // 3) current pain guard (M1 beyond minimum)
        if (($profile['health']['hasCurrentPain'] ?? false) === true) {
            $adjustments[] = [
                'code' => 'reduce_load',
                'severity' => 'high',
                'rationale' => 'User reported current pain',
                'evidence' => [['key' => 'hasCurrentPain', 'value' => true]],
                'params' => ['reductionPct' => 30],
            ];
        }

        // 5) no long run
        if (($signals['longRun']['exists'] ?? null) === false) {
            $adjustments[] = [
                'code' => 'add_long_run',
                'severity' => 'medium',
                'rationale' => 'No long run detected in recent training window',
                'evidence' => [['key' => 'longRun.exists', 'value' => false]],
            ];
        }

        // 6) surface constraints
        if (($profile['surfaces']['avoidAsphalt'] ?? null) === true) {
            $adjustments[] = [
                'code' => 'surface_constraint',
                'severity' => 'low',
                'rationale' => 'User prefers to avoid asphalt',
                'evidence' => [['key' => 'avoidAsphalt', 'value' => true]],
            ];
        }

        // 7) FeedbackSignals warnings (before AI — deterministic)
        if (($warnings['overloadRisk'] ?? null) === true) {
            $hasReduceLoad = false;
            foreach ($adjustments as $a) {
                if (($a['code'] ?? null) === 'reduce_load') {
                    $hasReduceLoad = true;
                    break;
                }
            }
            if (!$hasReduceLoad) {
                $adjustments[] = [
                    'code' => 'reduce_load',
                    'severity' => 'high',
                    'rationale' => 'Overload risk detected from latest workout',
                    'evidence' => [['key' => 'overloadRisk', 'value' => true]],
                    'params' => ['reductionPct' => 25],
                ];
            }
        }

        if (($warnings['hrInstability'] ?? null) === true) {
            $adjustments[] = [
                'code' => 'recovery_focus',
                'severity' => 'high',
                'rationale' => 'HR instability detected after easy workout',
                'evidence' => [['key' => 'hrInstability', 'value' => true]],
                'params' => ['replaceHardSessionWithEasy' => true, 'longRunReductionPct' => 15],
            ];
        }

        if (($warnings['economyDrop'] ?? null) === true) {
            $adjustments[] = [
                'code' => 'technique_focus',
                'severity' => 'medium',
                'rationale' => 'Economy drop detected after easy workout',
                'evidence' => [['key' => 'economyDrop', 'value' => true]],
                'params' => ['addStrides' => true, 'stridesCount' => 6, 'stridesDurationSec' => 20],
            ];
        }

        $adaptation = is_array($signals['adaptation'] ?? null) ? $signals['adaptation'] : [];

        if (($adaptation['missedKeyWorkout'] ?? false) === true) {
            $adjustments[] = [
                'code' => 'missed_workout_rebalance',
                'severity' => 'high',
                'rationale' => 'Detected missed key workout in recent plan window',
                'evidence' => [['key' => 'missedKeyWorkout', 'value' => true]],
                'params' => ['addMakeupEasySession' => true, 'makeupDurationMin' => 30],
            ];
        }

        if (($adaptation['harderThanPlanned'] ?? false) === true) {
            $adjustments[] = [
                'code' => 'harder_than_planned_guard',
                'severity' => 'high',
                'rationale' => 'Recent workouts were harder than planned',
                'evidence' => [['key' => 'harderThanPlanned', 'value' => true]],
                'params' => ['reductionPct' => 20, 'replaceHardSessionWithEasy' => true],
            ];
        }

        if ((int) ($adaptation['easierThanPlannedStreak'] ?? 0) >= 2) {
            $adjustments[] = [
                'code' => 'easier_than_planned_progression',
                'severity' => 'medium',
                'rationale' => 'Detected easier-than-planned streak; apply controlled progression',
                'evidence' => [['key' => 'easierThanPlannedStreak', 'value' => (int) $adaptation['easierThanPlannedStreak']]],
                'params' => ['increasePct' => 10],
            ];
        }

        if (($adaptation['controlStartRecent'] ?? false) === true) {
            $adjustments[] = [
                'code' => 'control_start_followup',
                'severity' => 'medium',
                'rationale' => 'Recent control start/race detected; apply follow-up recovery micro-adjustment',
                'evidence' => [['key' => 'controlStartRecent', 'value' => true]],
                'params' => ['longRunReductionPct' => 10, 'replaceHardSessionWithEasy' => true],
            ];
        }

        $normalized = $this->normalizeAdjustments($adjustments);

        try {
            Log::info('[TrainingAdjustments] generated', [
                'windowDays' => $context['windowDays'],
                'signals' => [
                    'fatigue' => $signals['flags']['fatigue'] ?? null,
                    'injuryRisk' => $signals['flags']['injuryRisk'] ?? null,
                    'longRunExists' => $signals['longRun']['exists'] ?? null,
                    'avoidAsphalt' => $profile['surfaces']['avoidAsphalt'] ?? null,
                    'hasCurrentPain' => $profile['health']['hasCurrentPain'] ?? null,
                    'missedKeyWorkout' => ($signals['adaptation']['missedKeyWorkout'] ?? false),
                    'harderThanPlanned' => ($signals['adaptation']['harderThanPlanned'] ?? false),
                    'easierThanPlannedStreak' => (int) ($signals['adaptation']['easierThanPlannedStreak'] ?? 0),
                    'controlStartRecent' => ($signals['adaptation']['controlStartRecent'] ?? false),
                ],
                'output' => [
                    'adjustmentCount' => count($normalized),
                    'codes' => array_column($normalized, 'code'),
                ],
            ]);
        } catch (\Throwable) {
            // Log facade not available in unit test context (no container).
        }

        return [
            'generatedAtIso' => $context['generatedAtIso'],
            'windowDays' => $context['windowDays'],
            'adjustments' => $normalized,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $adjustments
     * @return array<int,array<string,mixed>>
     */
    private function normalizeAdjustments(array $adjustments): array
    {
        /** @var array<string,array<string,mixed>> $byCode */
        $byCode = [];
        foreach ($adjustments as $adjustment) {
            $code = (string) ($adjustment['code'] ?? '');
            if ($code === '') {
                continue;
            }

            if (!isset($byCode[$code])) {
                $byCode[$code] = $adjustment;
                continue;
            }

            $byCode[$code] = $this->mergeAdjustment($byCode[$code], $adjustment);
        }

        // Keep insertion order by first occurrence of code to preserve API-level code ordering.
        return array_values($byCode);
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    private function mergeAdjustment(array $left, array $right): array
    {
        $leftSeverity = (string) ($left['severity'] ?? 'low');
        $rightSeverity = (string) ($right['severity'] ?? 'low');
        $leftRank = self::SEVERITY_RANK[$leftSeverity] ?? 0;
        $rightRank = self::SEVERITY_RANK[$rightSeverity] ?? 0;

        $base = $leftRank >= $rightRank ? $left : $right;
        $other = $leftRank >= $rightRank ? $right : $left;

        $baseEvidence = is_array($base['evidence'] ?? null) ? $base['evidence'] : [];
        $otherEvidence = is_array($other['evidence'] ?? null) ? $other['evidence'] : [];
        $base['evidence'] = array_values(array_merge($baseEvidence, $otherEvidence));

        $baseParams = is_array($base['params'] ?? null) ? $base['params'] : [];
        $otherParams = is_array($other['params'] ?? null) ? $other['params'] : [];
        $mergedParams = array_merge($baseParams, $otherParams);

        if (($base['code'] ?? null) === 'reduce_load') {
            $mergedParams['reductionPct'] = max(
                (int) ($baseParams['reductionPct'] ?? 0),
                (int) ($otherParams['reductionPct'] ?? 0),
            );
        }

        if (($base['code'] ?? null) === 'recovery_focus') {
            $mergedParams['replaceHardSessionWithEasy'] = (bool) (($baseParams['replaceHardSessionWithEasy'] ?? false) || ($otherParams['replaceHardSessionWithEasy'] ?? false));
            $mergedParams['longRunReductionPct'] = max(
                (int) ($baseParams['longRunReductionPct'] ?? 0),
                (int) ($otherParams['longRunReductionPct'] ?? 0),
            );
        }

        if (($base['code'] ?? null) === 'harder_than_planned_guard') {
            $mergedParams['reductionPct'] = max(
                (int) ($baseParams['reductionPct'] ?? 0),
                (int) ($otherParams['reductionPct'] ?? 0),
            );
            $mergedParams['replaceHardSessionWithEasy'] = (bool) (($baseParams['replaceHardSessionWithEasy'] ?? false) || ($otherParams['replaceHardSessionWithEasy'] ?? false));
        }

        if (($base['code'] ?? null) === 'easier_than_planned_progression') {
            $mergedParams['increasePct'] = max(
                (int) ($baseParams['increasePct'] ?? 0),
                (int) ($otherParams['increasePct'] ?? 0),
            );
        }

        $base['params'] = $mergedParams;
        return $base;
    }
}
