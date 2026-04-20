<?php

namespace App\Services;

/**
 * Port of backend/src/training-adjustments/training-adjustments.service.ts.
 * Pure logic, deterministic. No DB access.
 */
class TrainingAdjustmentsService
{
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
            ];
        }

        // 2) no long run
        if (($signals['longRun']['exists'] ?? null) === false) {
            $adjustments[] = [
                'code' => 'add_long_run',
                'severity' => 'medium',
                'rationale' => 'No long run detected in recent training window',
                'evidence' => [['key' => 'longRun.exists', 'value' => false]],
            ];
        }

        // 3) surface constraints
        if (($profile['surfaces']['avoidAsphalt'] ?? null) === true) {
            $adjustments[] = [
                'code' => 'surface_constraint',
                'severity' => 'low',
                'rationale' => 'User prefers to avoid asphalt',
                'evidence' => [['key' => 'avoidAsphalt', 'value' => true]],
            ];
        }

        // 4) FeedbackSignals warnings (before AI — deterministic)
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

        return [
            'generatedAtIso' => $context['generatedAtIso'],
            'windowDays' => $context['windowDays'],
            'adjustments' => $adjustments,
        ];
    }
}
