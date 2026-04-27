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
