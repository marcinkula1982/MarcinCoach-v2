<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Port of backend/src/training-adjustments/training-adjustments.service.ts.
 * Pure logic, deterministic. No DB access — DB wstrzykiwane przez PlanMemoryService.
 *
 * M3/M4 beyond current scope — Etap E:
 *  - adaptationType (volume | intensity | density | structure) na każdym adjustmentcie
 *  - confidence (low | medium | high)
 *  - decisionBasis (signals, weekHistory, blockContext)
 *  - nowe adjustmenty strukturalne
 *  - pamięć wielotygodniowa (przez PlanMemoryService)
 */
class TrainingAdjustmentsService
{
    private const SEVERITY_RANK = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
    ];

    public function __construct(
        private readonly ?PlanMemoryService $planMemoryService = null,
    ) {
    }

    /**
     * @param array<string,mixed>             $context
     * @param array<string,mixed>|null        $feedbackSignals
     * @param array<string,mixed>|null        $blockContext   wynik BlockPeriodizationService::resolve()
     *
     * @return array{
     *   generatedAtIso:string,
     *   windowDays:int,
     *   adjustments:array<int,array<string,mixed>>
     * }
     */
    public function generate(array $context, ?array $feedbackSignals = null, ?array $blockContext = null): array
    {
        // Fallback: pobierz blockContext z context, jeśli nie został podany jawnie.
        if ($blockContext === null && isset($context['blockContext']) && is_array($context['blockContext'])) {
            $blockContext = $context['blockContext'];
        }

        $adjustments = [];

        $signals = $context['signals'] ?? [];
        $profile = $context['profile'] ?? [];
        $warnings = $feedbackSignals['warnings'] ?? [];
        $userId = (int) ($profile['userId'] ?? $context['userId'] ?? 0);

        $recentWeeks = [];
        if ($this->planMemoryService !== null && $userId > 0) {
            try {
                $recentWeeks = $this->planMemoryService->getRecentWeeks($userId, 4);
            } catch (\Throwable) {
                $recentWeeks = [];
            }
        }

        $blockType = (string) ($blockContext['block_type'] ?? '');
        $weekRole = (string) ($blockContext['week_role'] ?? '');
        $focus = (string) ($blockContext['key_capability_focus'] ?? '');

        // 1) fatigue flag
        if (($signals['flags']['fatigue'] ?? null) === true) {
            $adjustments[] = [
                'code' => 'reduce_load',
                'severity' => 'high',
                'rationale' => 'Detected fatigue flag in recent training window',
                'evidence' => [['key' => 'fatigue', 'value' => true]],
                'params' => ['reductionPct' => 25],
                'adaptationType' => 'volume',
                'confidence' => 'high',
                'decisionBasis' => [
                    'signals' => ['fatigue'],
                    'blockContext' => $blockType !== '' ? $blockType : null,
                ],
            ];
        }

        // 2) injury risk guard
        if (($signals['flags']['injuryRisk'] ?? null) === true) {
            $adjustments[] = [
                'code' => 'recovery_focus',
                'severity' => 'high',
                'rationale' => 'Injury risk flag detected in recent training window',
                'evidence' => [['key' => 'injuryRisk', 'value' => true]],
                'params' => ['replaceHardSessionWithEasy' => true, 'longRunReductionPct' => 20],
                'adaptationType' => 'intensity',
                'confidence' => 'high',
                'decisionBasis' => ['signals' => ['injuryRisk']],
            ];
        }

        // 3) current pain guard
        if (($profile['health']['hasCurrentPain'] ?? false) === true) {
            $adjustments[] = [
                'code' => 'reduce_load',
                'severity' => 'high',
                'rationale' => 'User reported current pain',
                'evidence' => [['key' => 'hasCurrentPain', 'value' => true]],
                'params' => ['reductionPct' => 30],
                'adaptationType' => 'volume',
                'confidence' => 'high',
                'decisionBasis' => ['signals' => ['hasCurrentPain']],
            ];

            // PAIN + zaplanowany akcent → chroń long run kosztem quality
            if ($weekRole !== 'taper') {
                $adjustments[] = [
                    'code' => 'protect_long_run',
                    'severity' => 'high',
                    'rationale' => 'Pain with planned quality — protect long run, drop intensity',
                    'evidence' => [['key' => 'hasCurrentPain', 'value' => true]],
                    'params' => [],
                    'adaptationType' => 'structure',
                    'confidence' => 'high',
                    'decisionBasis' => [
                        'signals' => ['hasCurrentPain'],
                        'blockContext' => $weekRole,
                    ],
                ];
            }
        }

        // 5) no long run
        if (($signals['longRun']['exists'] ?? null) === false) {
            $adjustments[] = [
                'code' => 'add_long_run',
                'severity' => 'medium',
                'rationale' => 'No long run detected in recent training window',
                'evidence' => [['key' => 'longRun.exists', 'value' => false]],
                'adaptationType' => 'structure',
                'confidence' => 'medium',
                'decisionBasis' => ['signals' => ['longRun.exists=false']],
            ];
        }

        // 6) surface constraints
        if (($profile['surfaces']['avoidAsphalt'] ?? null) === true) {
            $adjustments[] = [
                'code' => 'surface_constraint',
                'severity' => 'low',
                'rationale' => 'User prefers to avoid asphalt',
                'evidence' => [['key' => 'avoidAsphalt', 'value' => true]],
                'adaptationType' => 'structure',
                'confidence' => 'high',
                'decisionBasis' => ['signals' => ['avoidAsphalt']],
            ];
        }

        // 7) FeedbackSignals warnings
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
                    'adaptationType' => 'volume',
                    'confidence' => 'high',
                    'decisionBasis' => ['signals' => ['overloadRisk']],
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
                'adaptationType' => 'intensity',
                'confidence' => 'medium',
                'decisionBasis' => ['signals' => ['hrInstability']],
            ];
        }

        if (($warnings['economyDrop'] ?? null) === true) {
            $adjustments[] = [
                'code' => 'technique_focus',
                'severity' => 'medium',
                'rationale' => 'Economy drop detected after easy workout',
                'evidence' => [['key' => 'economyDrop', 'value' => true]],
                'params' => ['addStrides' => true, 'stridesCount' => 6, 'stridesDurationSec' => 20],
                'adaptationType' => 'structure',
                'confidence' => 'medium',
                'decisionBasis' => ['signals' => ['economyDrop']],
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
                'adaptationType' => 'structure',
                'confidence' => 'high',
                'decisionBasis' => ['signals' => ['missedKeyWorkout']],
            ];
        }

        if (($adaptation['harderThanPlanned'] ?? false) === true) {
            $adjustments[] = [
                'code' => 'harder_than_planned_guard',
                'severity' => 'high',
                'rationale' => 'Recent workouts were harder than planned',
                'evidence' => [['key' => 'harderThanPlanned', 'value' => true]],
                'params' => ['reductionPct' => 20, 'replaceHardSessionWithEasy' => true],
                'adaptationType' => 'intensity',
                'confidence' => 'high',
                'decisionBasis' => ['signals' => ['harderThanPlanned']],
            ];
        }

        if ((int) ($adaptation['easierThanPlannedStreak'] ?? 0) >= 2) {
            $adjustments[] = [
                'code' => 'easier_than_planned_progression',
                'severity' => 'medium',
                'rationale' => 'Detected easier-than-planned streak; apply controlled progression',
                'evidence' => [['key' => 'easierThanPlannedStreak', 'value' => (int) $adaptation['easierThanPlannedStreak']]],
                'params' => ['increasePct' => 10],
                'adaptationType' => 'volume',
                'confidence' => 'medium',
                'decisionBasis' => ['signals' => ['easierThanPlannedStreak']],
            ];
        }

        if (($adaptation['controlStartRecent'] ?? false) === true) {
            $adjustments[] = [
                'code' => 'control_start_followup',
                'severity' => 'medium',
                'rationale' => 'Recent control start/race detected; apply follow-up recovery micro-adjustment',
                'evidence' => [['key' => 'controlStartRecent', 'value' => true]],
                'params' => ['longRunReductionPct' => 10, 'replaceHardSessionWithEasy' => true],
                'adaptationType' => 'volume',
                'confidence' => 'medium',
                'decisionBasis' => ['signals' => ['controlStartRecent']],
            ];
        }

        // ---- M3/M4 beyond current scope: adjustmenty strukturalne z pamięci ----

        // Chroń jakość — skróć easy (peak week + brak ryzyka)
        if (
            $blockType === 'peak' &&
            ($adaptation['harderThanPlanned'] ?? false) === false &&
            ($adaptation['missedKeyWorkout'] ?? false) === false &&
            ($signals['flags']['fatigue'] ?? false) === false
        ) {
            $adjustments[] = [
                'code' => 'protect_quality_shorten_easy',
                'severity' => 'medium',
                'rationale' => 'Peak week — protect quality session, trim easy volume',
                'evidence' => [['key' => 'blockType', 'value' => 'peak']],
                'params' => ['easyReductionPct' => 15],
                'adaptationType' => 'volume',
                'confidence' => 'medium',
                'decisionBasis' => ['blockContext' => 'peak_week'],
            ];
        }

        // Zamień interwały na fartlek tlenowy (fatigue + focus=vo2max)
        if (($signals['flags']['fatigue'] ?? false) === true && $focus === 'vo2max') {
            $adjustments[] = [
                'code' => 'swap_intervals_to_fartlek',
                'severity' => 'medium',
                'rationale' => 'Fatigue with vo2max focus — swap strict intervals for fartlek',
                'evidence' => [['key' => 'fatigue', 'value' => true], ['key' => 'focus', 'value' => 'vo2max']],
                'params' => [],
                'adaptationType' => 'structure',
                'confidence' => 'medium',
                'decisionBasis' => [
                    'signals' => ['fatigue'],
                    'blockContext' => 'vo2max_focus',
                ],
            ];
        }

        // Pamięć wielotygodniowa
        $memoryAdj = $this->generateFromMemory($recentWeeks, $blockContext);
        foreach ($memoryAdj as $m) {
            $adjustments[] = $m;
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
                'blockContext' => $blockContext,
                'recentWeeksCount' => count($recentWeeks),
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
     * Reguły oparte na historii tygodni. Wejście malejąco po week_start_date.
     *
     * @param array<int,array<string,mixed>> $recentWeeks
     * @param array<string,mixed>|null       $blockContext
     * @return array<int,array<string,mixed>>
     */
    private function generateFromMemory(array $recentWeeks, ?array $blockContext): array
    {
        $out = [];
        if (count($recentWeeks) < 2) {
            return $out;
        }

        // 1) 3 z 4 ostatnich tygodni actual < 80% planned → persistent_underexecution_check
        $underCount = 0;
        $considered = 0;
        foreach (array_slice($recentWeeks, 0, 4) as $w) {
            $planned = (int) ($w['planned_total_min'] ?? 0);
            $actual = (int) ($w['actual_total_min'] ?? 0);
            if ($planned > 0) {
                $considered++;
                if (($actual / $planned) < 0.80) {
                    $underCount++;
                }
            }
        }
        if ($considered >= 3 && $underCount >= 3) {
            $out[] = [
                'code' => 'persistent_underexecution_check',
                'severity' => 'medium',
                'rationale' => '3+ of last 4 weeks under-executed (<80% planned); review constraints',
                'evidence' => [['key' => 'weeksUnderExecuted', 'value' => $underCount]],
                'params' => [],
                'adaptationType' => 'volume',
                'confidence' => 'high',
                'decisionBasis' => [
                    'weekHistory' => 'last_4_weeks_under_80pct',
                    'blockContext' => $blockContext['block_type'] ?? null,
                ],
            ];
        }

        // 2) ostatnie 2 tygodnie actual_quality_count=0 → quality_session_missing_trend
        $lastTwoZeroQuality = true;
        $tw = array_slice($recentWeeks, 0, 2);
        if (count($tw) < 2) {
            $lastTwoZeroQuality = false;
        } else {
            foreach ($tw as $w) {
                $q = $w['actual_quality_count'];
                if ($q === null || (int) $q > 0) {
                    $lastTwoZeroQuality = false;
                    break;
                }
            }
        }
        if ($lastTwoZeroQuality) {
            $out[] = [
                'code' => 'quality_session_missing_trend',
                'severity' => 'medium',
                'rationale' => 'No quality session executed in last 2 weeks',
                'evidence' => [['key' => 'zeroQualityWeeks', 'value' => 2]],
                'params' => [],
                'adaptationType' => 'structure',
                'confidence' => 'medium',
                'decisionBasis' => ['weekHistory' => 'last_2_weeks_zero_quality'],
            ];
        }

        // 3) 4 tygodnie z rzędu load_direction=increase → force_recovery_week
        $increaseStreak = 0;
        foreach (array_slice($recentWeeks, 0, 4) as $w) {
            if (($w['load_direction'] ?? null) === 'increase') {
                $increaseStreak++;
            } else {
                break;
            }
        }
        if ($increaseStreak >= 4) {
            $out[] = [
                'code' => 'force_recovery_week',
                'severity' => 'high',
                'rationale' => '4 consecutive weeks of load increase — enforce recovery',
                'evidence' => [['key' => 'increaseStreak', 'value' => $increaseStreak]],
                'params' => [],
                'adaptationType' => 'volume',
                'confidence' => 'high',
                'decisionBasis' => ['weekHistory' => 'four_weeks_increase_streak'],
            ];
        }

        // 4) 2+ tygodni actual_quality_count >= 3 → reduce_intensity_density
        $highDensityStreak = 0;
        foreach (array_slice($recentWeeks, 0, 4) as $w) {
            $qc = $w['actual_quality_count'];
            if ($qc !== null && (int) $qc >= 3) {
                $highDensityStreak++;
            } else {
                break;
            }
        }
        if ($highDensityStreak >= 2) {
            $out[] = [
                'code' => 'reduce_intensity_density',
                'severity' => 'medium',
                'rationale' => 'High density of quality sessions for 2+ weeks — reduce density, keep volume',
                'evidence' => [['key' => 'highDensityStreak', 'value' => $highDensityStreak]],
                'params' => [],
                'adaptationType' => 'density',
                'confidence' => 'medium',
                'decisionBasis' => ['weekHistory' => 'two_weeks_high_density'],
            ];
        }

        return $out;
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

        // confidence: max z obu (high > medium > low)
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        $bc = (string) ($base['confidence'] ?? 'medium');
        $oc = (string) ($other['confidence'] ?? 'medium');
        $base['confidence'] = ($rank[$bc] ?? 0) >= ($rank[$oc] ?? 0) ? $bc : $oc;

        // decisionBasis: merge listy sygnałów
        $bSignals = $base['decisionBasis']['signals'] ?? [];
        $oSignals = $other['decisionBasis']['signals'] ?? [];
        if (is_array($bSignals) || is_array($oSignals)) {
            $base['decisionBasis'] = array_merge(
                is_array($base['decisionBasis'] ?? null) ? $base['decisionBasis'] : [],
                is_array($other['decisionBasis'] ?? null) ? $other['decisionBasis'] : [],
            );
            $base['decisionBasis']['signals'] = array_values(array_unique(array_merge(
                is_array($bSignals) ? $bSignals : [],
                is_array($oSignals) ? $oSignals : [],
            )));
        }

        return $base;
    }
}
