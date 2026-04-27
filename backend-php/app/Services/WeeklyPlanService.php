<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class WeeklyPlanService
{
    private const WEEK_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param array<string,mixed>      $context
     * @param array<string,mixed>|null $adjustments
     * @param array<string,mixed>|null $blockContext  M3/M4 — wynik BlockPeriodizationService::resolve()
     * @return array<string,mixed>
     */
    public function generatePlan(array $context, ?array $adjustments = null, ?array $blockContext = null): array
    {
        // Fallback: pobierz blockContext z context, jeśli nie został podany jawnie.
        if ($blockContext === null && isset($context['blockContext']) && is_array($context['blockContext'])) {
            $blockContext = $context['blockContext'];
        }

        $generatedAt = CarbonImmutable::parse((string) ($context['generatedAtIso'] ?? now()->toISOString()))->utc();
        $weekStart = $generatedAt->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $weekEnd = $weekStart->addDays(6)->endOfDay();

        $runningDays = $context['profile']['runningDays'] ?? self::WEEK_DAYS;
        $hasFatigue = (bool) ($context['signals']['flags']['fatigue'] ?? false);
        $weeklyLoad = (float) ($context['signals']['weeklyLoad'] ?? 0.0);
        $rolling4wLoad = (float) ($context['signals']['rolling4wLoad'] ?? 0.0);
        $crossTrainingFatigueLoad = (float) ($context['signals']['crossTrainingFatigueLoad'] ?? 0.0);
        $overallFatigueLoad = (float) ($context['signals']['overallFatigueLoad'] ?? ($weeklyLoad + $crossTrainingFatigueLoad));
        // PHP TrainingSignals contract freeze: use totalWorkouts (not legacy volume.sessions).
        $sessionsCount = (int) ($context['signals']['totalWorkouts'] ?? 0);
        $canQuality = ($sessionsCount >= 3) && !$hasFatigue;
        $loadScale = $this->resolveLoadScale($weeklyLoad, $rolling4wLoad, $blockContext);

        if ($overallFatigueLoad > $weeklyLoad && $overallFatigueLoad >= max(120.0, $weeklyLoad * 1.35) && $loadScale > 0.90) {
            $loadScale = 0.90;
        }

        // loadSpike cap — niezależnie od bloku, ogranicz do 0.85
        $loadSpike = (bool) ($context['signals']['flags']['loadSpike'] ?? false);
        if ($loadSpike && $loadScale > 0.85) {
            $loadScale = 0.85;
        }

        $sessions = [];
        foreach (self::WEEK_DAYS as $day) {
            $sessions[] = [
                'day' => $day,
                'type' => in_array($day, $runningDays, true) ? 'easy' : 'rest',
                'durationMin' => 0,
            ];
        }

        $longRunDay = in_array('sun', $runningDays, true) ? 'sun' : (in_array('sat', $runningDays, true) ? 'sat' : ($runningDays[0] ?? 'sun'));
        $longDurationBase = $hasFatigue ? 75 : 90;
        $easyDurationBase = $hasFatigue ? 35 : 40;
        foreach ($sessions as &$s) {
            if ($s['day'] === $longRunDay) {
                $s['type'] = 'long';
                $s['durationMin'] = $this->roundToFive($longDurationBase * $loadScale);
                $s['intensityHint'] = 'Z2';
            } elseif ($s['type'] === 'easy') {
                $s['durationMin'] = $this->roundToFive($easyDurationBase * $loadScale);
                $s['intensityHint'] = 'Z2';
            }
        }
        unset($s);

        if ($canQuality) {
            $qualityDay = $this->selectQualityDay($sessions, $longRunDay);
            if ($qualityDay !== null) {
                foreach ($sessions as &$s) {
                    if ($s['day'] === $qualityDay && $s['type'] === 'easy') {
                        $this->applyQualityShape($s, $loadScale, $blockContext);
                        break;
                    }
                }
                unset($s);
            }
        }

        foreach (($adjustments['adjustments'] ?? []) as $adj) {
            $code = (string) ($adj['code'] ?? '');
            if ($code === 'reduce_load') {
                $pct = (int) ($adj['params']['reductionPct'] ?? 20);
                $factor = max(0.0, 1.0 - ($pct / 100));
                foreach ($sessions as &$s) {
                    if ($this->isQualityLike($s)) {
                        $s['type'] = 'easy';
                        $s['durationMin'] = 40;
                        $s['intensityHint'] = 'Z2';
                        unset($s['structure']);
                    }
                    if (($s['durationMin'] ?? 0) > 0) {
                        $s['durationMin'] = (int) (round(((float) $s['durationMin']) * $factor / 5) * 5);
                    }
                }
                unset($s);
            }

            if ($code === 'recovery_focus') {
                $replaceHard = (bool) ($adj['params']['replaceHardSessionWithEasy'] ?? true);
                $longRunReductionPct = (int) ($adj['params']['longRunReductionPct'] ?? 15);
                $longRunFactor = max(0.0, 1.0 - ($longRunReductionPct / 100));

                foreach ($sessions as &$s) {
                    if ($replaceHard && $this->isQualityLike($s)) {
                        $s['type'] = 'easy';
                        $s['durationMin'] = 40;
                        $s['intensityHint'] = 'Z2';
                        unset($s['structure']);
                    }

                    if (($s['type'] ?? null) === 'long' && ($s['durationMin'] ?? 0) > 0) {
                        $s['durationMin'] = (int) (round(((float) $s['durationMin']) * $longRunFactor / 5) * 5);
                    }
                }
                unset($s);
            }

            if ($code === 'add_long_run' && !$this->hasSessionType($sessions, 'long')) {
                $targetLongRunDay = $this->resolveLongRunDay($runningDays);
                foreach ($sessions as &$s) {
                    if ($s['day'] === $targetLongRunDay) {
                        $s['type'] = 'long';
                        $s['durationMin'] = $this->roundToFive(80 * $loadScale);
                        $s['intensityHint'] = 'Z2';
                        break;
                    }
                }
                unset($s);
                $longRunDay = $targetLongRunDay;
            }

            if ($code === 'technique_focus' && (bool) ($adj['params']['addStrides'] ?? false)) {
                $stridesCount = (int) ($adj['params']['stridesCount'] ?? 6);
                $stridesDurationSec = (int) ($adj['params']['stridesDurationSec'] ?? 20);
                foreach ($sessions as &$s) {
                    if (($s['type'] ?? null) === 'easy') {
                        $s['techniqueFocus'] = [
                            'type' => 'strides',
                            'stridesCount' => $stridesCount,
                            'stridesDurationSec' => $stridesDurationSec,
                        ];
                        break;
                    }
                }
                unset($s);
            }

            if ($code === 'surface_constraint') {
                foreach ($sessions as &$s) {
                    if (in_array((string) ($s['type'] ?? ''), ['easy', 'quality', 'long', 'threshold', 'intervals', 'fartlek', 'tempo'], true)) {
                        $s['surfaceHint'] = 'avoid_asphalt';
                    }
                }
                unset($s);
            }

            if ($code === 'missed_workout_rebalance' && (bool) ($adj['params']['addMakeupEasySession'] ?? false)) {
                $makeupDuration = max(15, (int) ($adj['params']['makeupDurationMin'] ?? 30));
                foreach ($sessions as &$s) {
                    if (($s['type'] ?? null) === 'rest') {
                        $s['type'] = 'easy';
                        $s['durationMin'] = $this->roundToFive($makeupDuration);
                        $s['intensityHint'] = 'Z1-Z2';
                        break;
                    }
                }
                unset($s);
            }

            if ($code === 'harder_than_planned_guard') {
                $pct = (int) ($adj['params']['reductionPct'] ?? 20);
                $factor = max(0.0, 1.0 - ($pct / 100));
                $replaceHard = (bool) ($adj['params']['replaceHardSessionWithEasy'] ?? true);
                foreach ($sessions as &$s) {
                    if ($replaceHard && $this->isQualityLike($s)) {
                        $s['type'] = 'easy';
                        $s['durationMin'] = 40;
                        $s['intensityHint'] = 'Z2';
                        unset($s['structure']);
                    }
                    if (($s['durationMin'] ?? 0) > 0) {
                        $s['durationMin'] = (int) (round(((float) $s['durationMin']) * $factor / 5) * 5);
                    }
                }
                unset($s);
            }

            if ($code === 'easier_than_planned_progression') {
                $pct = (int) ($adj['params']['increasePct'] ?? 10);
                $factor = min(1.20, 1.0 + ($pct / 100));
                foreach ($sessions as &$s) {
                    if (in_array((string) ($s['type'] ?? ''), ['easy', 'long'], true) && ($s['durationMin'] ?? 0) > 0) {
                        $s['durationMin'] = (int) (round(((float) $s['durationMin']) * $factor / 5) * 5);
                    }
                }
                unset($s);
            }

            if ($code === 'control_start_followup') {
                $replaceHard = (bool) ($adj['params']['replaceHardSessionWithEasy'] ?? true);
                $longRunReductionPct = (int) ($adj['params']['longRunReductionPct'] ?? 10);
                $longRunFactor = max(0.0, 1.0 - ($longRunReductionPct / 100));
                foreach ($sessions as &$s) {
                    if ($replaceHard && $this->isQualityLike($s)) {
                        $s['type'] = 'easy';
                        $s['durationMin'] = 40;
                        $s['intensityHint'] = 'Z2';
                        unset($s['structure']);
                    }
                    if (($s['type'] ?? null) === 'long' && ($s['durationMin'] ?? 0) > 0) {
                        $s['durationMin'] = (int) (round(((float) $s['durationMin']) * $longRunFactor / 5) * 5);
                    }
                }
                unset($s);
            }

            // ---- M3/M4 beyond current scope: nowe adjustmenty strukturalne ----

            if ($code === 'protect_quality_shorten_easy') {
                // Chroń jakość; przytnij easy o 15%
                $easyCutPct = (int) ($adj['params']['easyReductionPct'] ?? 15);
                $easyFactor = max(0.0, 1.0 - ($easyCutPct / 100));
                foreach ($sessions as &$s) {
                    if (($s['type'] ?? null) === 'easy' && ($s['durationMin'] ?? 0) > 0) {
                        $s['durationMin'] = (int) (round(((float) $s['durationMin']) * $easyFactor / 5) * 5);
                    }
                }
                unset($s);
            }

            if ($code === 'swap_intervals_to_fartlek') {
                foreach ($sessions as &$s) {
                    $type = (string) ($s['type'] ?? '');
                    if (in_array($type, ['quality', 'intervals', 'threshold'], true)) {
                        $s['type'] = 'fartlek';
                        $s['intensityHint'] = 'Z3-Z4';
                        $s['structure'] = '8×1min zmiennie Z3/Z4, trucht 1min';
                    }
                }
                unset($s);
            }

            if ($code === 'reduce_intensity_density') {
                // Usuń jeden akcent tygodnia jeśli są dwa; objętość zostaje.
                $qualityCount = 0;
                foreach ($sessions as &$s) {
                    if ($this->isQualityLike($s)) {
                        $qualityCount++;
                        if ($qualityCount > 1) {
                            $s['type'] = 'easy';
                            $s['intensityHint'] = 'Z2';
                            unset($s['structure']);
                        }
                    }
                }
                unset($s);
            }

            if ($code === 'protect_long_run') {
                // Chroń long run — skróć/usuń quality, zachowaj długi.
                foreach ($sessions as &$s) {
                    if ($this->isQualityLike($s)) {
                        $s['type'] = 'easy';
                        $s['durationMin'] = 35;
                        $s['intensityHint'] = 'Z2';
                        unset($s['structure']);
                    }
                }
                unset($s);
            }

            if ($code === 'force_recovery_week') {
                // Wymuszony recovery week: wszystko easy, short long run.
                foreach ($sessions as &$s) {
                    if ($this->isQualityLike($s)) {
                        $s['type'] = 'easy';
                        $s['durationMin'] = 35;
                        $s['intensityHint'] = 'Z2';
                        unset($s['structure']);
                    }
                    if (($s['type'] ?? null) === 'long' && ($s['durationMin'] ?? 0) > 0) {
                        $s['durationMin'] = (int) (round(((float) $s['durationMin']) * 0.70 / 5) * 5);
                    }
                    if (($s['type'] ?? null) === 'easy' && ($s['durationMin'] ?? 0) > 0) {
                        $s['durationMin'] = (int) (round(((float) $s['durationMin']) * 0.85 / 5) * 5);
                    }
                }
                unset($s);
            }
        }

        // Apply maxSessionMin cap from user profile (M1 beyond minimum)
        $maxSessionMin = (int) ($context['profile']['availability']['maxSessionMin'] ?? 0);
        if ($maxSessionMin > 0) {
            foreach ($sessions as &$s) {
                if (($s['durationMin'] ?? 0) > $maxSessionMin) {
                    $s['durationMin'] = $this->roundToFive($maxSessionMin);
                }
            }
            unset($s);
        }

        $sessions = $this->enforceQualityDensityGuard($sessions, $longRunDay);
        $sessions = (new TrainingSessionBlocksService())->withBlocks($sessions);
        $totalDuration = array_reduce($sessions, fn ($acc, $s) => $acc + (int) ($s['durationMin'] ?? 0), 0);
        $qualityCount = count(array_filter($sessions, fn ($s) => $this->isQualityLike($s)));

        $sessionTypes = array_column($sessions, 'type');
        $appliedCodes = array_values(array_map(
            fn ($a) => (string) ($a['code'] ?? ''),
            $adjustments['adjustments'] ?? [],
        ));
        try {
            Log::info('[WeeklyPlan] generated', [
                'userId' => $context['profile']['userId'] ?? null,
                'windowDays' => $context['windowDays'] ?? 28,
                'weekStart' => $weekStart->toDateString(),
                'signals' => [
                    'weeklyLoad' => $weeklyLoad,
                    'rolling4wLoad' => $rolling4wLoad,
                    'crossTrainingFatigueLoad' => $crossTrainingFatigueLoad,
                    'overallFatigueLoad' => $overallFatigueLoad,
                    'sessionsCount' => $sessionsCount,
                    'hasFatigue' => $hasFatigue,
                    'canQuality' => $canQuality,
                    'loadScale' => $loadScale,
                ],
                'output' => [
                    'totalDurationMin' => $totalDuration,
                    'qualitySessions' => $qualityCount,
                    'longRunDay' => $longRunDay,
                    'sessionTypes' => $sessionTypes,
                    'appliedAdjustmentsCodes' => $appliedCodes,
                ],
                'blockContext' => $blockContext,
            ]);
        } catch (\Throwable) {
            // Log facade not available in unit test context (no container).
        }

        $output = [
            'generatedAtIso' => $generatedAt->toISOString(),
            'weekStartIso' => $weekStart->toISOString(),
            'weekEndIso' => $weekEnd->toISOString(),
            'windowDays' => (int) ($context['windowDays'] ?? 28),
            'inputsHash' => hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'sessions' => $sessions,
            'summary' => [
                'totalDurationMin' => $totalDuration,
                'crossTrainingDurationMin' => 0,
                'overallFatigueLoadMin' => round($overallFatigueLoad, 2),
                'qualitySessions' => $qualityCount,
                'longRunDay' => $longRunDay,
            ],
            'rationale' => [
                'Weekly plan based on recent training context',
                $hasFatigue ? 'Reduced intensity due to fatigue flag' : 'Standard progression with deterministic rules',
            ],
        ];

        if (is_array($blockContext) && !empty($blockContext)) {
            $output['blockContext'] = [
                'block_type' => (string) ($blockContext['block_type'] ?? ''),
                'week_role' => (string) ($blockContext['week_role'] ?? ''),
                'block_goal' => (string) ($blockContext['block_goal'] ?? ''),
                'load_direction' => (string) ($blockContext['load_direction'] ?? ''),
                'key_capability_focus' => (string) ($blockContext['key_capability_focus'] ?? ''),
            ];
        }

        return $output;
    }

    /**
     * Ustawia kształt jakościowej sesji w zależności od focus/block.
     * @param array<string,mixed> $s
     */
    private function applyQualityShape(array &$s, float $loadScale, ?array $blockContext): void
    {
        $focus = (string) ($blockContext['key_capability_focus'] ?? '');
        $blockType = (string) ($blockContext['block_type'] ?? '');

        // Default (jak dotychczas)
        $type = 'quality';
        $hint = 'Z3';
        $structure = null;
        $baseMin = 50;

        if ($blockType === 'build' && $focus === 'threshold') {
            $type = 'threshold';
            $hint = 'Z3';
            $structure = '3×10min Z3 z 2min przerwy trucht';
            $baseMin = 55;
        } elseif ($blockType === 'peak' && $focus === 'vo2max') {
            $type = 'intervals';
            $hint = 'Z4-Z5';
            $structure = '5×3min Z4/Z5 z 3min truchtu';
            $baseMin = 55;
        } elseif ($focus === 'economy') {
            $type = 'fartlek';
            $hint = 'Z3-Z4';
            $structure = '8×1min zmiennie Z3/Z4';
            $baseMin = 45;
        } elseif ($blockType === 'taper') {
            $type = 'tempo';
            $hint = 'Z3';
            $structure = '15min Z3 w środku 40min easy';
            $baseMin = 40;
        }

        $s['type'] = $type;
        $s['durationMin'] = $this->roundToFive($baseMin * $loadScale);
        $s['intensityHint'] = $hint;
        if ($structure !== null) {
            $s['structure'] = $structure;
        }
    }

    /**
     * Quality-like = każdy z: quality, threshold, intervals, fartlek, tempo.
     * @param array<string,mixed> $s
     */
    private function isQualityLike(array $s): bool
    {
        $t = (string) ($s['type'] ?? '');
        return in_array($t, ['quality', 'threshold', 'intervals', 'fartlek', 'tempo'], true);
    }

    private function resolveLoadScale(float $weeklyLoad, float $rolling4wLoad, ?array $blockContext = null): float
    {
        // M3/M4: jeśli blockContext sterujemy bezpośrednio wg week_role / load_direction
        if (is_array($blockContext) && !empty($blockContext)) {
            $role = (string) ($blockContext['week_role'] ?? '');
            $direction = (string) ($blockContext['load_direction'] ?? '');

            if ($role === 'recovery') {
                return 0.70;
            }
            if ($role === 'taper') {
                return 0.60;
            }
            if ($direction === 'increase') {
                return $this->applyRatioCapForIncrease($weeklyLoad, $rolling4wLoad);
            }
            if ($direction === 'decrease') {
                return 0.85;
            }
            if ($direction === 'maintain') {
                return 1.00;
            }
        }

        // Legacy fallback
        return $this->resolveLegacyLoadScale($weeklyLoad, $rolling4wLoad);
    }

    private function applyRatioCapForIncrease(float $weeklyLoad, float $rolling4wLoad): float
    {
        $reference = $rolling4wLoad > 0 ? ($rolling4wLoad / 4.0) : $weeklyLoad;
        if ($reference <= 0.0) {
            return 1.10;
        }
        $ratio = $weeklyLoad / $reference;
        // Gdy weeklyLoad już znacząco powyżej baseline — nie podbijaj dalej
        if ($ratio > 1.15) {
            return 0.90;
        }
        return 1.10;
    }

    private function resolveLegacyLoadScale(float $weeklyLoad, float $rolling4wLoad): float
    {
        $reference = $rolling4wLoad > 0 ? ($rolling4wLoad / 4.0) : $weeklyLoad;
        if ($reference <= 0.0) {
            return 1.0;
        }

        $ratio = $weeklyLoad / $reference;
        if ($ratio > 1.15) {
            return 0.90;
        }
        if ($ratio < 0.85) {
            return 1.10;
        }
        return 1.00;
    }

    private function roundToFive(float $minutes): int
    {
        return (int) (round($minutes / 5) * 5);
    }

    /**
     * @param array<int,array<string,mixed>> $sessions
     */
    private function hasSessionType(array $sessions, string $type): bool
    {
        foreach ($sessions as $session) {
            if (($session['type'] ?? null) === $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,string> $runningDays
     */
    private function resolveLongRunDay(array $runningDays): string
    {
        return in_array('sun', $runningDays, true) ? 'sun' : (in_array('sat', $runningDays, true) ? 'sat' : ($runningDays[0] ?? 'sun'));
    }

    /**
     * @param array<int,array<string,mixed>> $sessions
     */
    private function selectQualityDay(array $sessions, string $longRunDay): ?string
    {
        $longIdx = array_search($longRunDay, self::WEEK_DAYS, true);
        foreach (['tue', 'wed', 'thu'] as $day) {
            $dayIdx = array_search($day, self::WEEK_DAYS, true);
            if (!is_int($dayIdx) || !is_int($longIdx)) {
                continue;
            }
            if (abs($dayIdx - $longIdx) <= 1) {
                continue;
            }

            foreach ($sessions as $session) {
                if (($session['day'] ?? null) === $day && ($session['type'] ?? null) === 'easy') {
                    return $day;
                }
            }
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $sessions
     * @return array<int,array<string,mixed>>
     */
    private function enforceQualityDensityGuard(array $sessions, string $longRunDay): array
    {
        $longIdx = array_search($longRunDay, self::WEEK_DAYS, true);
        $keptQuality = false;
        foreach ($sessions as &$session) {
            if (!$this->isQualityLike($session)) {
                continue;
            }

            $dayIdx = array_search((string) ($session['day'] ?? ''), self::WEEK_DAYS, true);
            $tooCloseToLongRun = is_int($dayIdx) && is_int($longIdx) && abs($dayIdx - $longIdx) <= 1;
            if ($keptQuality || $tooCloseToLongRun) {
                $session['type'] = 'easy';
                $session['intensityHint'] = 'Z2';
                unset($session['structure']);
            } else {
                $keptQuality = true;
            }
        }
        unset($session);

        return $sessions;
    }
}
