<?php

namespace Tests\Unit;

use App\Services\TrainingAdjustmentsService;
use PHPUnit\Framework\TestCase;

class TrainingAdjustmentsServiceTest extends TestCase
{
    private TrainingAdjustmentsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TrainingAdjustmentsService();
    }

    private function baseContext(array $overrides = []): array
    {
        $defaults = [
            'generatedAtIso' => '2026-04-20T12:00:00Z',
            'windowDays' => 28,
            'signals' => [
                'longRun' => ['exists' => true],
                'flags' => ['injuryRisk' => false, 'fatigue' => false],
            ],
            'profile' => [
                'surfaces' => ['preferTrail' => false, 'avoidAsphalt' => false],
            ],
        ];
        return array_replace_recursive($defaults, $overrides);
    }

    public function test_empty_adjustments_when_all_good(): void
    {
        $result = $this->service->generate($this->baseContext());
        $this->assertSame('2026-04-20T12:00:00Z', $result['generatedAtIso']);
        $this->assertSame(28, $result['windowDays']);
        $this->assertSame([], $result['adjustments']);
    }

    public function test_fatigue_flag_produces_reduce_load_high(): void
    {
        $ctx = $this->baseContext(['signals' => ['flags' => ['fatigue' => true]]]);
        $result = $this->service->generate($ctx);

        $this->assertCount(1, $result['adjustments']);
        $this->assertSame('reduce_load', $result['adjustments'][0]['code']);
        $this->assertSame('high', $result['adjustments'][0]['severity']);
        $this->assertSame('fatigue', $result['adjustments'][0]['evidence'][0]['key']);
        $this->assertTrue($result['adjustments'][0]['evidence'][0]['value']);
    }

    public function test_no_long_run_produces_add_long_run_medium(): void
    {
        $ctx = $this->baseContext(['signals' => ['longRun' => ['exists' => false]]]);
        $result = $this->service->generate($ctx);

        $this->assertCount(1, $result['adjustments']);
        $this->assertSame('add_long_run', $result['adjustments'][0]['code']);
        $this->assertSame('medium', $result['adjustments'][0]['severity']);
    }

    public function test_injury_risk_flag_produces_recovery_focus_high(): void
    {
        $ctx = $this->baseContext(['signals' => ['flags' => ['injuryRisk' => true]]]);
        $result = $this->service->generate($ctx);

        $this->assertCount(1, $result['adjustments']);
        $this->assertSame('recovery_focus', $result['adjustments'][0]['code']);
        $this->assertSame('high', $result['adjustments'][0]['severity']);
        $this->assertTrue($result['adjustments'][0]['params']['replaceHardSessionWithEasy']);
    }

    public function test_return_after_break_signal_maps_to_recovery_focus_guard(): void
    {
        // In minimal P6.2, return-after-break is represented by injuryRisk=true.
        $ctx = $this->baseContext([
            'signals' => [
                'flags' => ['injuryRisk' => true, 'fatigue' => false],
            ],
        ]);

        $result = $this->service->generate($ctx);
        $codes = array_map(fn ($a) => $a['code'], $result['adjustments']);
        $this->assertContains('recovery_focus', $codes);
    }

    public function test_avoid_asphalt_produces_surface_constraint_low(): void
    {
        $ctx = $this->baseContext(['profile' => ['surfaces' => ['avoidAsphalt' => true]]]);
        $result = $this->service->generate($ctx);

        $this->assertCount(1, $result['adjustments']);
        $this->assertSame('surface_constraint', $result['adjustments'][0]['code']);
        $this->assertSame('low', $result['adjustments'][0]['severity']);
    }

    public function test_feedback_overload_risk_adds_reduce_load_with_params(): void
    {
        $feedback = ['warnings' => ['overloadRisk' => true]];
        $result = $this->service->generate($this->baseContext(), $feedback);

        $this->assertCount(1, $result['adjustments']);
        $this->assertSame('reduce_load', $result['adjustments'][0]['code']);
        $this->assertSame(25, $result['adjustments'][0]['params']['reductionPct']);
    }

    public function test_overload_risk_does_not_duplicate_when_fatigue_already_set(): void
    {
        $ctx = $this->baseContext(['signals' => ['flags' => ['fatigue' => true]]]);
        $feedback = ['warnings' => ['overloadRisk' => true]];

        $result = $this->service->generate($ctx, $feedback);

        // Fatigue already produced reduce_load — overload risk should NOT add a second reduce_load.
        $reduceLoadAdjustments = array_filter(
            $result['adjustments'],
            fn ($a) => $a['code'] === 'reduce_load'
        );
        $this->assertCount(1, $reduceLoadAdjustments);
    }

    public function test_hr_instability_adds_recovery_focus(): void
    {
        $feedback = ['warnings' => ['hrInstability' => true]];
        $result = $this->service->generate($this->baseContext(), $feedback);

        $this->assertCount(1, $result['adjustments']);
        $this->assertSame('recovery_focus', $result['adjustments'][0]['code']);
        $this->assertTrue($result['adjustments'][0]['params']['replaceHardSessionWithEasy']);
        $this->assertSame(15, $result['adjustments'][0]['params']['longRunReductionPct']);
    }

    public function test_economy_drop_adds_technique_focus(): void
    {
        $feedback = ['warnings' => ['economyDrop' => true]];
        $result = $this->service->generate($this->baseContext(), $feedback);

        $this->assertCount(1, $result['adjustments']);
        $this->assertSame('technique_focus', $result['adjustments'][0]['code']);
        $this->assertTrue($result['adjustments'][0]['params']['addStrides']);
        $this->assertSame(6, $result['adjustments'][0]['params']['stridesCount']);
        $this->assertSame(20, $result['adjustments'][0]['params']['stridesDurationSec']);
    }

    public function test_all_rules_combined(): void
    {
        $ctx = $this->baseContext([
            'signals' => [
                'longRun' => ['exists' => false],
                'flags' => ['fatigue' => true],
            ],
            'profile' => ['surfaces' => ['avoidAsphalt' => true]],
        ]);
        $feedback = [
            'warnings' => [
                'overloadRisk' => true,   // deduped against fatigue
                'hrInstability' => true,
                'economyDrop' => true,
            ],
        ];

        $result = $this->service->generate($ctx, $feedback);

        $codes = array_map(fn ($a) => $a['code'], $result['adjustments']);
        $this->assertSame(['reduce_load', 'add_long_run', 'surface_constraint', 'recovery_focus', 'technique_focus'], $codes);
    }

    public function test_null_feedback_signals_still_works(): void
    {
        $result = $this->service->generate($this->baseContext(), null);
        $this->assertSame([], $result['adjustments']);
    }

    public function test_recovery_focus_is_deduplicated_with_deterministic_priority(): void
    {
        $ctx = $this->baseContext(['signals' => ['flags' => ['injuryRisk' => true]]]);
        $feedback = ['warnings' => ['hrInstability' => true]];

        $result = $this->service->generate($ctx, $feedback);
        $recoveryFocus = array_values(array_filter(
            $result['adjustments'],
            fn ($a) => ($a['code'] ?? null) === 'recovery_focus'
        ));

        $this->assertCount(1, $recoveryFocus);
        $this->assertSame('high', $recoveryFocus[0]['severity']);
        $this->assertTrue($recoveryFocus[0]['params']['replaceHardSessionWithEasy']);
        $this->assertSame(20, $recoveryFocus[0]['params']['longRunReductionPct']);
    }

    // --- M1 beyond minimum: hasCurrentPain ---

    public function test_current_pain_produces_reduce_load_high(): void
    {
        $ctx = $this->baseContext([
            'profile' => ['health' => ['hasCurrentPain' => true]],
        ]);

        $result = $this->service->generate($ctx);

        $this->assertCount(1, $result['adjustments']);
        $this->assertSame('reduce_load', $result['adjustments'][0]['code']);
        $this->assertSame('high', $result['adjustments'][0]['severity']);
        $this->assertSame(30, $result['adjustments'][0]['params']['reductionPct']);
        $this->assertSame('hasCurrentPain', $result['adjustments'][0]['evidence'][0]['key']);
    }

    public function test_current_pain_false_produces_no_adjustment(): void
    {
        $ctx = $this->baseContext([
            'profile' => ['health' => ['hasCurrentPain' => false]],
        ]);

        $result = $this->service->generate($ctx);
        $this->assertSame([], $result['adjustments']);
    }

    public function test_current_pain_merges_with_fatigue_keeping_max_reduction(): void
    {
        $ctx = $this->baseContext([
            'signals' => ['flags' => ['fatigue' => true]],
            'profile' => ['health' => ['hasCurrentPain' => true]],
        ]);

        $result = $this->service->generate($ctx);

        $reduceLoadItems = array_values(array_filter(
            $result['adjustments'],
            fn ($a) => $a['code'] === 'reduce_load'
        ));
        // Deduped into one reduce_load with max(25, 30) = 30
        $this->assertCount(1, $reduceLoadItems);
        $this->assertSame(30, $reduceLoadItems[0]['params']['reductionPct']);
        $this->assertSame('high', $reduceLoadItems[0]['severity']);
    }

    public function test_current_pain_with_injury_risk_both_present(): void
    {
        $ctx = $this->baseContext([
            'signals' => ['flags' => ['injuryRisk' => true]],
            'profile' => ['health' => ['hasCurrentPain' => true]],
        ]);

        $result = $this->service->generate($ctx);

        $codes = array_map(fn ($a) => $a['code'], $result['adjustments']);
        $this->assertContains('recovery_focus', $codes);
        $this->assertContains('reduce_load', $codes);
    }

    public function test_m4_adaptation_signals_produce_expected_adjustment_codes(): void
    {
        $ctx = $this->baseContext([
            'signals' => [
                'adaptation' => [
                    'missedKeyWorkout' => true,
                    'harderThanPlanned' => true,
                    'easierThanPlannedStreak' => 2,
                    'controlStartRecent' => true,
                ],
            ],
        ]);

        $result = $this->service->generate($ctx);
        $codes = array_map(fn ($a) => $a['code'], $result['adjustments']);

        $this->assertContains('missed_workout_rebalance', $codes);
        $this->assertContains('harder_than_planned_guard', $codes);
        $this->assertContains('easier_than_planned_progression', $codes);
        $this->assertContains('control_start_followup', $codes);
    }
}
