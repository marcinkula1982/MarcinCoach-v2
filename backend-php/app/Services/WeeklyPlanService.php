<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class WeeklyPlanService
{
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

        $runningDays = $context['profile']['runningDays'] ?? ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $hasFatigue = (bool) ($context['signals']['flags']['fatigue'] ?? false);
        $canQuality = ((int) ($context['signals']['volume']['sessions'] ?? 0) >= 3) && !$hasFatigue;

        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $sessions = [];
        foreach ($days as $day) {
            $sessions[] = [
                'day' => $day,
                'type' => in_array($day, $runningDays, true) ? 'easy' : 'rest',
                'durationMin' => 0,
            ];
        }

        $longRunDay = in_array('sun', $runningDays, true) ? 'sun' : (in_array('sat', $runningDays, true) ? 'sat' : ($runningDays[0] ?? 'sun'));
        foreach ($sessions as &$s) {
            if ($s['day'] === $longRunDay) {
                $s['type'] = 'long';
                $s['durationMin'] = $hasFatigue ? 75 : 90;
                $s['intensityHint'] = 'Z2';
            } elseif ($s['type'] === 'easy') {
                $s['durationMin'] = $hasFatigue ? 35 : 40;
                $s['intensityHint'] = 'Z2';
            }
        }
        unset($s);

        if ($canQuality) {
            foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $d) {
                foreach ($sessions as &$s) {
                    if ($s['day'] === $d && $s['type'] === 'easy') {
                        $s['type'] = 'quality';
                        $s['durationMin'] = 50;
                        $s['intensityHint'] = 'Z3';
                        unset($s);
                        break 2;
                    }
                }
            }
        }

        foreach (($adjustments['adjustments'] ?? []) as $adj) {
            if (($adj['code'] ?? null) === 'reduce_load') {
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
        }

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
}
