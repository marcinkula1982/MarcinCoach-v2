<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class WeeklyPlanService
{
    private const WEEK_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed>|null $adjustments
     * @return array<string,mixed>
     */
    public function generatePlan(array $context, ?array $adjustments = null): array
    {
        $generatedAt = CarbonImmutable::parse((string) ($context['generatedAtIso'] ?? now()->toISOString()))->utc();
        $weekStart = $generatedAt->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $weekEnd = $weekStart->addDays(6)->endOfDay();

        $runningDays = $context['profile']['runningDays'] ?? self::WEEK_DAYS;
        $hasFatigue = (bool) ($context['signals']['flags']['fatigue'] ?? false);
        $weeklyLoad = (float) ($context['signals']['weeklyLoad'] ?? 0.0);
        $rolling4wLoad = (float) ($context['signals']['rolling4wLoad'] ?? 0.0);
        // PHP TrainingSignals contract freeze: use totalWorkouts (not legacy volume.sessions).
        $sessionsCount = (int) ($context['signals']['totalWorkouts'] ?? 0);
        $canQuality = ($sessionsCount >= 3) && !$hasFatigue;
        $loadScale = $this->resolveLoadScale($weeklyLoad, $rolling4wLoad);

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
                        $s['type'] = 'quality';
                        $s['durationMin'] = $this->roundToFive(50 * $loadScale);
                        $s['intensityHint'] = 'Z3';
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
                    if ($s['type'] === 'quality') {
                        $s['type'] = 'easy';
                        $s['durationMin'] = 40;
                        $s['intensityHint'] = 'Z2';
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
                    if ($replaceHard && ($s['type'] ?? null) === 'quality') {
                        $s['type'] = 'easy';
                        $s['durationMin'] = 40;
                        $s['intensityHint'] = 'Z2';
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
                    if (in_array((string) ($s['type'] ?? ''), ['easy', 'quality', 'long'], true)) {
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
                    if ($replaceHard && ($s['type'] ?? null) === 'quality') {
                        $s['type'] = 'easy';
                        $s['durationMin'] = 40;
                        $s['intensityHint'] = 'Z2';
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
                    if ($replaceHard && ($s['type'] ?? null) === 'quality') {
                        $s['type'] = 'easy';
                        $s['durationMin'] = 40;
                        $s['intensityHint'] = 'Z2';
                    }
                    if (($s['type'] ?? null) === 'long' && ($s['durationMin'] ?? 0) > 0) {
                        $s['durationMin'] = (int) (round(((float) $s['durationMin']) * $longRunFactor / 5) * 5);
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
        $totalDuration = array_reduce($sessions, fn ($acc, $s) => $acc + (int) ($s['durationMin'] ?? 0), 0);
        $qualityCount = count(array_filter($sessions, fn ($s) => ($s['type'] ?? '') === 'quality'));

        return [
            'generatedAtIso' => $generatedAt->toISOString(),
            'weekStartIso' => $weekStart->toISOString(),
            'weekEndIso' => $weekEnd->toISOString(),
            'windowDays' => (int) ($context['windowDays'] ?? 28),
            'inputsHash' => hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'sessions' => $sessions,
            'summary' => [
                'totalDurationMin' => $totalDuration,
                'qualitySessions' => $qualityCount,
                'longRunDay' => $longRunDay,
            ],
            'rationale' => [
                'Weekly plan based on recent training context',
                $hasFatigue ? 'Reduced intensity due to fatigue flag' : 'Standard progression with deterministic rules',
            ],
        ];
    }

    private function resolveLoadScale(float $weeklyLoad, float $rolling4wLoad): float
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
            if (($session['type'] ?? null) !== 'quality') {
                continue;
            }

            $dayIdx = array_search((string) ($session['day'] ?? ''), self::WEEK_DAYS, true);
            $tooCloseToLongRun = is_int($dayIdx) && is_int($longIdx) && abs($dayIdx - $longIdx) <= 1;
            if ($keptQuality || $tooCloseToLongRun) {
                $session['type'] = 'easy';
                $session['intensityHint'] = 'Z2';
            } else {
                $keptQuality = true;
            }
        }
        unset($session);

        return $sessions;
    }
}
