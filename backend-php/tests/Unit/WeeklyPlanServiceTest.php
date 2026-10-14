<?php

namespace Tests\Unit;

use App\Services\WeeklyPlanService;
use PHPUnit\Framework\TestCase;

class WeeklyPlanServiceTest extends TestCase
{
    private WeeklyPlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WeeklyPlanService();
    }

    private function baseContext(array $overrides = []): array
    {
        $defaults = [
            'generatedAtIso' => '2026-04-24T10:00:00Z',
            'windowDays' => 28,
            'signals' => [
                'weeklyLoad' => 120.0,
                'rolling4wLoad' => 480.0,
                'totalWorkouts' => 4,
                'flags' => ['fatigue' => false],
            ],
            'profile' => [
                'runningDays' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
                'surfaces' => ['avoidAsphalt' => false],
            ],
        ];

        return array_replace_recursive($defaults, $overrides);
    }

    public function test_scales_durations_when_weekly_load_is_high_vs_rolling_baseline(): void
    {
        $context = $this->baseContext([
            'signals' => [
                'weeklyLoad' => 180.0,
                'rolling4wLoad' => 480.0,
            ],
        ]);

        $plan = $this->service->generatePlan($context, ['adjustments' => []]);
        $sessions = $plan['sessions'];
        $long = array_values(array_filter($sessions, fn ($s) => ($s['type'] ?? null) === 'long'))[0];

        $this->assertSame(80, $long['durationMin']); // 90 * 0.90 => 81 -> 80 (rounded to 5)
    }

    public function test_quality_density_guard_keeps_max_one_quality_and_not_adjacent_to_long_run(): void
    {
        $context = $this->baseContext([
            'signals' => [
                'totalWorkouts' => 6,
                'flags' => ['fatigue' => false],
            ],
        ]);

        $plan = $this->service->generatePlan($context, ['adjustments' => []]);
        $sessions = $plan['sessions'];

        $qualities = array_values(array_filter($sessions, fn ($s) => ($s['type'] ?? null) === 'quality'));
        $this->assertCount(1, $qualities);

        $weekDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $qDay = $qualities[0]['day'];
        $longDay = $plan['summary']['longRunDay'];
        $qIdx = array_search($qDay, $weekDays, true);
        $longIdx = array_search($longDay, $weekDays, true);
        $this->assertIsInt($qIdx);
        $this->assertIsInt($longIdx);
        $this->assertGreaterThan(1, abs($qIdx - $longIdx));
        $this->assertSame(1, $plan['summary']['qualitySessions']);
    }

    public function test_running_sessions_include_preview_blocks(): void
    {
        $context = $this->baseContext([
            'signals' => [
                'totalWorkouts' => 6,
                'flags' => ['fatigue' => false],
            ],
        ]);

        $plan = $this->service->generatePlan($context, ['adjustments' => []]);
        $running = array_values(array_filter($plan['sessions'], fn ($s) => ($s['type'] ?? null) !== 'rest' && (int) ($s['durationMin'] ?? 0) > 0));

        $this->assertNotEmpty($running);
        foreach ($running as $session) {
            $this->assertArrayHasKey('blocks', $session);
            $this->assertSame('warmup', $session['blocks'][0]['kind']);
            $this->assertStringContainsString('Rozgrzewka ważniejsza niż trening', $session['blocks'][0]['description']);
            $this->assertSame('cooldown', $session['blocks'][count($session['blocks']) - 1]['kind']);
            $this->assertStringContainsString('mięśnie błagają', $session['blocks'][count($session['blocks']) - 1]['description']);
        }
    }

    public function test_quality_session_blocks_expose_main_structure(): void
    {
        $context = $this->baseContext(['signals' => ['totalWorkouts' => 5]]);
        $blockContext = [
            'block_type' => 'peak',
            'block_goal' => 'Peak VO2max',
            'week_role' => 'peak',
            'load_direction' => 'increase',
            'key_capability_focus' => 'vo2max',
        ];

        $plan = $this->service->generatePlan($context, ['adjustments' => []], $blockContext);
        $intervals = array_values(array_filter($plan['sessions'], fn ($s) => ($s['type'] ?? null) === 'intervals'))[0];
        $main = array_values(array_filter($intervals['blocks'], fn ($b) => ($b['kind'] ?? null) === 'main'))[0];

        $this->assertSame('Interwały', $main['title']);
        $this->assertStringContainsString('5×3min', $main['description']);
        $this->assertSame('Z4-Z5', $main['intensityHint']);
    }

    public function test_applies_technique_focus_and_surface_constraint_to_sessions(): void
    {
        $context = $this->baseContext();
        $context['profile']['runningDays'] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $adjustments = [
            'adjustments' => [
                [
                    'code' => 'technique_focus',
                    'params' => ['addStrides' => true, 'stridesCount' => 6, 'stridesDurationSec' => 20],
                ],
                [
                    'code' => 'surface_constraint',
                    'params' => [],
                ],
            ],
        ];

        $plan = $this->service->generatePlan($context, $adjustments);
        $sessions = $plan['sessions'];

        $easyWithTechnique = array_values(array_filter(
            $sessions,
            fn ($s) => ($s['type'] ?? null) === 'easy' && isset($s['techniqueFocus']['type'])
        ));
        $this->assertNotEmpty($easyWithTechnique);
        $this->assertSame('strides', $easyWithTechnique[0]['techniqueFocus']['type']);
        $this->assertSame(6, $easyWithTechnique[0]['techniqueFocus']['stridesCount']);
        $this->assertSame(20, $easyWithTechnique[0]['techniqueFocus']['stridesDurationSec']);

        $runningSessions = array_values(array_filter($sessions, fn ($s) => in_array(($s['type'] ?? ''), ['easy', 'quality', 'long'], true)));
        $this->assertNotEmpty($runningSessions);
        foreach ($runningSessions as $session) {
            $this->assertSame('avoid_asphalt', $session['surfaceHint']);
        }
    }

    public function test_applies_m4_adaptation_adjustments_to_plan_shape(): void
    {
        $context = $this->baseContext();
        $adjustments = [
            'adjustments' => [
                ['code' => 'missed_workout_rebalance', 'params' => ['addMakeupEasySession' => true, 'makeupDurationMin' => 30]],
                ['code' => 'harder_than_planned_guard', 'params' => ['reductionPct' => 20, 'replaceHardSessionWithEasy' => true]],
                ['code' => 'easier_than_planned_progression', 'params' => ['increasePct' => 10]],
                ['code' => 'control_start_followup', 'params' => ['longRunReductionPct' => 10, 'replaceHardSessionWithEasy' => true]],
            ],
        ];

        $plan = $this->service->generatePlan($context, $adjustments);
        $sessions = $plan['sessions'];

        $this->assertNotEmpty(array_values(array_filter($sessions, fn ($s) => ($s['type'] ?? null) === 'easy' && (int) ($s['durationMin'] ?? 0) >= 25)));
        $qualityCount = count(array_filter($sessions, fn ($s) => ($s['type'] ?? null) === 'quality'));
        $this->assertLessThanOrEqual(1, $qualityCount);
        $long = array_values(array_filter($sessions, fn ($s) => ($s['type'] ?? null) === 'long'));
        $this->assertNotEmpty($long);
        $this->assertLessThanOrEqual(90, (int) $long[0]['durationMin']);
    }

    public function test_missed_key_workout_caps_load_scale_at_one(): void
    {
        // weeklyLoad 80 vs rolling baseline 120 -> ratio 0.67 < 0.85 -> legacy loadScale 1.10.
        $lowLoad = ['weeklyLoad' => 80.0, 'rolling4wLoad' => 480.0];

        $baseline = $this->service->generatePlan($this->baseContext(['signals' => $lowLoad]), ['adjustments' => []]);
        $baselineLong = array_values(array_filter($baseline['sessions'], fn ($s) => ($s['type'] ?? null) === 'long'))[0];
        $this->assertSame(100, $baselineLong['durationMin']); // 90 * 1.10 = 99 -> 100

        $capped = $this->service->generatePlan($this->baseContext([
            'signals' => $lowLoad + ['adaptation' => ['missedKeyWorkout' => true]],
        ]), ['adjustments' => []]);
        $cappedLong = array_values(array_filter($capped['sessions'], fn ($s) => ($s['type'] ?? null) === 'long'))[0];
        $this->assertSame(90, $cappedLong['durationMin']); // loadScale capped to 1.00

        // Cap must not raise an already lower scale (ratio > 1.15 -> 0.90).
        $highLoad = ['weeklyLoad' => 180.0, 'rolling4wLoad' => 480.0, 'adaptation' => ['missedKeyWorkout' => true]];
        $reduced = $this->service->generatePlan($this->baseContext(['signals' => $highLoad]), ['adjustments' => []]);
        $reducedLong = array_values(array_filter($reduced['sessions'], fn ($s) => ($s['type'] ?? null) === 'long'))[0];
        $this->assertSame(80, $reducedLong['durationMin']);

        // missedKeyWorkout=false keeps baseline.
        $unchanged = $this->service->generatePlan($this->baseContext([
            'signals' => $lowLoad + ['adaptation' => ['missedKeyWorkout' => false]],
        ]), ['adjustments' => []]);
        $this->assertSame($baseline['sessions'], $unchanged['sessions']);
    }

    // --- D1: makeup session must not land in the past ---

    /**
     * baseContext() generatedAtIso 2026-04-24 (Fri) -> week Mon 2026-04-20 .. Sun 2026-04-26.
     * runningDays tue/thu/sun -> rest days: mon(20), wed(22), fri(24), sat(25).
     */
    private function makeupContext(?string $todayIso): array
    {
        $context = $this->baseContext();
        $context['profile'] = [
            'runningDays' => ['tue', 'thu', 'sun'],
            'surfaces' => ['avoidAsphalt' => false],
        ];
        if ($todayIso !== null) {
            $context['todayIso'] = $todayIso;
        }

        return $context;
    }

    private function makeupAdjustments(): array
    {
        return [
            'adjustments' => [
                ['code' => 'missed_workout_rebalance', 'params' => ['addMakeupEasySession' => true, 'makeupDurationMin' => 30]],
            ],
        ];
    }

    public function test_missed_workout_rebalance_skips_days_before_today(): void
    {
        // Today = Thursday 23.04 -> mon(20) and wed(22) are past, fri(24) is the first future rest day.
        $plan = $this->service->generatePlan($this->makeupContext('2026-04-23'), $this->makeupAdjustments());
        $byDay = array_column($plan['sessions'], null, 'day');

        $this->assertSame('rest', $byDay['mon']['type']);
        $this->assertSame('rest', $byDay['wed']['type']);
        $this->assertSame('easy', $byDay['fri']['type']);
        $this->assertSame(30, $byDay['fri']['durationMin']);
        $this->assertSame('Z1-Z2', $byDay['fri']['intensityHint']);
        $this->assertSame('rest', $byDay['sat']['type']);
        $this->assertNotContains('makeup pominięty: brak wolnego dnia w przyszłości', $plan['rationale']);

        // Today itself is allowed: today = Wednesday 22.04 -> makeup on wed.
        $todayPlan = $this->service->generatePlan($this->makeupContext('2026-04-22'), $this->makeupAdjustments());
        $todayByDay = array_column($todayPlan['sessions'], null, 'day');
        $this->assertSame('rest', $todayByDay['mon']['type']);
        $this->assertSame('easy', $todayByDay['wed']['type']);
        $this->assertSame('rest', $todayByDay['fri']['type']);
    }

    public function test_missed_workout_rebalance_without_future_rest_day_adds_nothing(): void
    {
        // Today = Sunday 26.04 -> every rest day of the week is in the past.
        $plan = $this->service->generatePlan($this->makeupContext('2026-04-26'), $this->makeupAdjustments());
        $noAdjustment = $this->service->generatePlan($this->makeupContext('2026-04-26'), ['adjustments' => []]);

        $this->assertSame($noAdjustment['sessions'], $plan['sessions']);
        foreach (['mon', 'wed', 'fri', 'sat'] as $day) {
            $this->assertSame('rest', array_column($plan['sessions'], null, 'day')[$day]['type']);
        }
        $this->assertContains('makeup pominięty: brak wolnego dnia w przyszłości', $plan['rationale']);
        $this->assertNotContains('makeup pominięty: brak wolnego dnia w przyszłości', $noAdjustment['rationale']);
    }

    public function test_missed_workout_rebalance_without_today_iso_behaves_as_before(): void
    {
        $plan = $this->service->generatePlan($this->makeupContext(null), $this->makeupAdjustments());
        $byDay = array_column($plan['sessions'], null, 'day');

        // No date filter: first rest day in week order (Monday) gets the makeup, even though it is "past".
        $this->assertSame('easy', $byDay['mon']['type']);
        $this->assertSame(30, $byDay['mon']['durationMin']);
        $this->assertSame('rest', $byDay['wed']['type']);
        $this->assertNotContains('makeup pominięty: brak wolnego dnia w przyszłości', $plan['rationale']);

        // Empty string is treated as "no todayIso".
        $emptyPlan = $this->service->generatePlan($this->makeupContext(''), $this->makeupAdjustments());
        $this->assertSame($plan['sessions'], $emptyPlan['sessions']);
    }

    public function test_missed_workout_rebalance_skips_unavailable_days(): void
    {
        $adjustments = [
            'adjustments' => [
                ['code' => 'missed_workout_rebalance', 'params' => ['addMakeupEasySession' => true, 'makeupDurationMin' => 30]],
            ],
        ];

        // NOTE: baseContext() uses array_replace_recursive, which merges list arrays by index,
        // so the profile is assigned explicitly instead of being passed as an override.
        $profileFor = static fn (array $unavailableDays, bool $conflict = false): array => [
            'runningDays' => ['tue', 'thu', 'sun'],
            'surfaces' => ['avoidAsphalt' => false],
            'availability' => [
                'runningDays' => ['tue', 'thu', 'sun'],
                'unavailableDays' => $unavailableDays,
                'unavailableDaysConflict' => $conflict,
            ],
        ];

        // Baseline (no unavailableDays): first rest day in week order is Monday.
        $baselineContext = $this->baseContext();
        $baselineContext['profile'] = $profileFor([]);
        $baseline = $this->service->generatePlan($baselineContext, $adjustments);
        $baselineByDay = array_column($baseline['sessions'], null, 'day');
        $this->assertSame('easy', $baselineByDay['mon']['type']);
        $this->assertSame(30, $baselineByDay['mon']['durationMin']);

        // With mon/wed unavailable: makeup goes to the first allowed rest day (Friday).
        $planContext = $this->baseContext();
        $planContext['profile'] = $profileFor(['mon', 'wed']);
        $plan = $this->service->generatePlan($planContext, $adjustments);
        $byDay = array_column($plan['sessions'], null, 'day');

        $this->assertSame('rest', $byDay['mon']['type']);
        $this->assertSame('rest', $byDay['wed']['type']);
        $this->assertSame('easy', $byDay['fri']['type']);
        $this->assertSame(30, $byDay['fri']['durationMin']);
        $this->assertSame('rest', $byDay['sat']['type']);

        // Conflict flag means unavailableDays are ignored -> behaves like baseline.
        $conflictContext = $this->baseContext();
        $conflictContext['profile'] = $profileFor(['mon', 'wed'], true);
        $conflict = $this->service->generatePlan($conflictContext, $adjustments);
        $conflictByDay = array_column($conflict['sessions'], null, 'day');
        $this->assertSame('easy', $conflictByDay['mon']['type']);
        $this->assertContains('unavailableDays pominięte: konflikt z runningDays', $conflict['rationale']);
        $this->assertNotContains('unavailableDays pominięte: konflikt z runningDays', $plan['rationale']);
    }

    // --- M1 beyond minimum: maxSessionMin cap ---

    public function test_max_session_min_caps_all_session_durations(): void
    {
        $context = $this->baseContext([
            'profile' => [
                'availability' => ['runningDays' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'maxSessionMin' => 45],
            ],
        ]);

        $plan = $this->service->generatePlan($context, ['adjustments' => []]);

        foreach ($plan['sessions'] as $session) {
            $duration = (int) ($session['durationMin'] ?? 0);
            $this->assertLessThanOrEqual(45, $duration, "Session on {$session['day']} exceeds cap: {$duration}");
        }
    }

    public function test_max_session_min_rounds_cap_to_five(): void
    {
        // cap of 47 should be rounded to 45 (roundToFive)
        $context = $this->baseContext([
            'profile' => [
                'availability' => ['runningDays' => ['mon', 'tue', 'wed', 'sat', 'sun'], 'maxSessionMin' => 47],
            ],
        ]);

        $plan = $this->service->generatePlan($context, ['adjustments' => []]);

        foreach ($plan['sessions'] as $session) {
            $duration = (int) ($session['durationMin'] ?? 0);
            if ($duration > 0) {
                $this->assertSame(0, $duration % 5, "Session duration {$duration} is not a multiple of 5");
            }
        }
    }

    public function test_zero_max_session_min_applies_no_cap(): void
    {
        $context = $this->baseContext([
            'profile' => [
                'availability' => ['runningDays' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'maxSessionMin' => 0],
            ],
        ]);

        $plan = $this->service->generatePlan($context, ['adjustments' => []]);
        $long = array_values(array_filter($plan['sessions'], fn ($s) => ($s['type'] ?? null) === 'long'))[0];

        // Without cap, long run should be ≥ 75 min (standard load)
        $this->assertGreaterThanOrEqual(75, (int) $long['durationMin']);
    }

    public function test_missing_availability_in_profile_applies_no_cap(): void
    {
        // profile has no 'availability' key at all — cap should be skipped
        $context = $this->baseContext();

        $plan = $this->service->generatePlan($context, ['adjustments' => []]);
        $long = array_values(array_filter($plan['sessions'], fn ($s) => ($s['type'] ?? null) === 'long'))[0];

        $this->assertGreaterThanOrEqual(75, (int) $long['durationMin']);
    }

    // --- M3/M4 beyond current scope: blockContext driven loadScale + quality shape ---

    public function test_block_context_recovery_week_scales_load_to_0_70(): void
    {
        $context = $this->baseContext(['signals' => ['totalWorkouts' => 5]]);
        $blockContext = [
            'block_type' => 'build',
            'block_goal' => 'Rozbudowa bazy',
            'week_role' => 'recovery',
            'load_direction' => 'decrease',
            'key_capability_focus' => 'aerobic_base',
        ];

        $plan = $this->service->generatePlan($context, ['adjustments' => []], $blockContext);
        $long = array_values(array_filter($plan['sessions'], fn ($s) => ($s['type'] ?? null) === 'long'))[0];

        // 90 * 0.70 = 63 → round to 65
        $this->assertSame(65, (int) $long['durationMin']);
        $this->assertSame('build', $plan['blockContext']['block_type']);
        $this->assertSame('recovery', $plan['blockContext']['week_role']);
    }

    public function test_block_context_taper_week_scales_load_to_0_60(): void
    {
        $context = $this->baseContext();
        $blockContext = [
            'block_type' => 'taper',
            'block_goal' => 'Taper',
            'week_role' => 'taper',
            'load_direction' => 'decrease',
            'key_capability_focus' => 'economy',
        ];

        $plan = $this->service->generatePlan($context, ['adjustments' => []], $blockContext);
        $long = array_values(array_filter($plan['sessions'], fn ($s) => ($s['type'] ?? null) === 'long'))[0];

        // 90 * 0.60 = 54 → round to 55
        $this->assertSame(55, (int) $long['durationMin']);
    }

    public function test_block_context_build_threshold_shapes_quality_as_threshold(): void
    {
        $context = $this->baseContext(['signals' => ['totalWorkouts' => 5]]);
        $blockContext = [
            'block_type' => 'build',
            'block_goal' => 'Build tempa',
            'week_role' => 'build',
            'load_direction' => 'increase',
            'key_capability_focus' => 'threshold',
        ];

        $plan = $this->service->generatePlan($context, ['adjustments' => []], $blockContext);
        $threshold = array_values(array_filter($plan['sessions'], fn ($s) => ($s['type'] ?? null) === 'threshold'));

        $this->assertNotEmpty($threshold, 'Expected at least one threshold quality session');
        $this->assertSame('Z3', $threshold[0]['intensityHint']);
        $this->assertStringContainsString('3×10min Z3', $threshold[0]['structure'] ?? '');
    }

    public function test_block_context_peak_vo2max_shapes_quality_as_intervals(): void
    {
        $context = $this->baseContext(['signals' => ['totalWorkouts' => 5]]);
        $blockContext = [
            'block_type' => 'peak',
            'block_goal' => 'Peak VO2max',
            'week_role' => 'peak',
            'load_direction' => 'increase',
            'key_capability_focus' => 'vo2max',
        ];

        $plan = $this->service->generatePlan($context, ['adjustments' => []], $blockContext);
        $intervals = array_values(array_filter($plan['sessions'], fn ($s) => ($s['type'] ?? null) === 'intervals'));

        $this->assertNotEmpty($intervals);
        $this->assertSame('Z4-Z5', $intervals[0]['intensityHint']);
        $this->assertStringContainsString('5×3min', $intervals[0]['structure'] ?? '');
    }

    public function test_block_context_falls_back_to_context_when_arg_null(): void
    {
        $context = $this->baseContext();
        $context['blockContext'] = [
            'block_type' => 'recovery',
            'block_goal' => 'Recovery',
            'week_role' => 'recovery',
            'load_direction' => 'decrease',
            'key_capability_focus' => 'aerobic_base',
        ];

        $plan = $this->service->generatePlan($context, ['adjustments' => []]);

        $this->assertArrayHasKey('blockContext', $plan);
        $this->assertSame('recovery', $plan['blockContext']['block_type']);
        // recovery role → loadScale 0.70
        $long = array_values(array_filter($plan['sessions'], fn ($s) => ($s['type'] ?? null) === 'long'))[0];
        $this->assertSame(65, (int) $long['durationMin']);
    }
}
