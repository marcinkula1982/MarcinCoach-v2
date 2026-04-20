<?php

namespace App\Services;

/**
 * Port of backend/src/training-feedback-v2/feedback-signals.mapper.ts.
 * Deterministic; no dependencies.
 */
class FeedbackSignalsMapper
{
    /**
     * @param array{
     *   character:string,
     *   coachSignals:array{hrStable:bool,...},
     *   metrics:array{paceEquality:float,weeklyLoadContribution:float,...}
     * } $feedback
     * @return array{
     *   intensityClass:string,
     *   hrStable:bool,
     *   economyFlag:string,
     *   loadImpact:string,
     *   warnings:array{economyDrop?:bool,hrInstability?:bool,overloadRisk?:bool}
     * }
     */
    public static function mapFeedbackToSignals(array $feedback): array
    {
        $character = $feedback['character'] ?? 'easy';

        // intensityClass
        $intensityClass = match ($character) {
            'easy', 'regeneracja' => 'easy',
            'tempo' => 'moderate',
            'interwał' => 'hard',
            default => 'easy',
        };

        $hrStable = (bool) ($feedback['coachSignals']['hrStable'] ?? false);

        $paceEquality = (float) ($feedback['metrics']['paceEquality'] ?? 0);
        if ($paceEquality > 0.8) {
            $economyFlag = 'good';
        } elseif ($paceEquality > 0.6) {
            $economyFlag = 'ok';
        } else {
            $economyFlag = 'poor';
        }

        $weeklyLoadContribution = (float) ($feedback['metrics']['weeklyLoadContribution'] ?? 0);
        if ($weeklyLoadContribution > 50) {
            $loadImpact = 'high';
        } elseif ($weeklyLoadContribution > 25) {
            $loadImpact = 'medium';
        } else {
            $loadImpact = 'low';
        }

        $warnings = [];
        if ($loadImpact === 'high' || $weeklyLoadContribution > 50) {
            $warnings['overloadRisk'] = true;
        }
        if (!$hrStable && $character === 'easy') {
            $warnings['hrInstability'] = true;
        }
        if ($economyFlag === 'poor' && $character === 'easy') {
            $warnings['economyDrop'] = true;
        }

        return [
            'intensityClass' => $intensityClass,
            'hrStable' => $hrStable,
            'economyFlag' => $economyFlag,
            'loadImpact' => $loadImpact,
            'warnings' => $warnings,
        ];
    }
}
