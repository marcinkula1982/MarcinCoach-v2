<?php

namespace Tests\Unit;

use App\Services\BlockPeriodizationService;
use PHPUnit\Framework\TestCase;

/**
 * M3/M4 beyond current scope — Etap B.
 * Czyste testy deterministyczne (bez DB).
 */
class BlockPeriodizationServiceTest extends TestCase
{
    private BlockPeriodizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlockPeriodizationService();
    }

    /**
     * @param array<string,mixed> $profileOverrides
     * @param array<string,mixed> $signalsOverrides
     */
    private function makeInputs(array $profileOverrides = [], array $signalsOverrides = []): array
    {
        $profile = array_replace_recursive([
            'primaryRace' => null,
            'health' => ['hasCurrentPain' => false],
        ], $profileOverrides);

        $signals = array_replace_recursive([
            'generatedAtIso' => '2026-04-20T10:00:00Z',
            'windowEnd' => '2026-04-20T10:00:00Z',
            'weeklyLoad' => 100.0,
            'rolling4wLoad' => 400.0,
            'longRun' => ['exists' => true],
            'flags' => ['injuryRisk' => false, 'fatigue' => false],
            'adaptation' => ['postRaceWeek' => false],
        ], $signalsOverrides);

        return [$profile, $signals];
    }

    public function test_taper_when_weeks_until_race_le_2(): void
    {
        [$profile, $signals] = $this->makeInputs([
            'primaryRace' => ['date' => '2026-05-01', 'distanceKm' => 42.2, 'priority' => 'A'],
        ]);
        // anchor 2026-04-20 → race 2026-05-01 → 11 dni = 1 tydzień
        $out = $this->service->resolve($profile, $signals, []);

        $this->assertSame('taper', $out['block_type']);
        $this->assertSame('taper', $out['week_role']);
        $this->assertSame('decrease', $out['load_direction']);
        $this->assertSame('economy', $out['key_capability_focus']);
        $this->assertSame(1, $out['weeks_until_race']);
    }

    public function test_peak_when_weeks_until_race_in_3_6(): void
    {
        [$profile, $signals] = $this->makeInputs([
            'primaryRace' => ['date' => '2026-05-25', 'distanceKm' => 21.1, 'priority' => 'A'],
        ]);
        $out = $this->service->resolve($profile, $signals, []);

        $this->assertSame('peak', $out['block_type']);
        $this->assertSame('peak', $out['week_role']);
        $this->assertSame('vo2max', $out['key_capability_focus']);
    }

    public function test_return_when_injury_risk_true(): void
    {
        [$profile, $signals] = $this->makeInputs([], [
            'flags' => ['injuryRisk' => true],
        ]);
        $out = $this->service->resolve($profile, $signals, []);

        $this->assertSame('return', $out['block_type']);
        $this->assertSame('recovery', $out['week_role']);
        $this->assertSame('decrease', $out['load_direction']);
        $this->assertSame('aerobic_base', $out['key_capability_focus']);
    }

    public function test_return_when_has_current_pain(): void
    {
        [$profile, $signals] = $this->makeInputs([
            'health' => ['hasCurrentPain' => true],
        ]);
        $out = $this->service->resolve($profile, $signals, []);

        $this->assertSame('return', $out['block_type']);
        $this->assertSame('recovery', $out['week_role']);
    }

    public function test_recovery_when_post_race_week(): void
    {
        [$profile, $signals] = $this->makeInputs([], [
            'adaptation' => ['postRaceWeek' => true],
        ]);
        $out = $this->service->resolve($profile, $signals, []);

        $this->assertSame('recovery', $out['block_type']);
        $this->assertSame('recovery', $out['week_role']);
    }

    public function test_build_when_last_3_weeks_planned_total_min_rising(): void
    {
        [$profile, $signals] = $this->makeInputs();
        // malejąco po week_start_date: 0=najnowszy
        $recent = [
            ['week_start_date' => '2026-04-13', 'planned_total_min' => 300, 'block_type' => 'base'],
            ['week_start_date' => '2026-04-06', 'planned_total_min' => 270, 'block_type' => 'base'],
            ['week_start_date' => '2026-03-30', 'planned_total_min' => 240, 'block_type' => 'base'],
        ];
        $out = $this->service->resolve($profile, $signals, $recent);

        $this->assertSame('build', $out['block_type']);
    }

    public function test_base_as_default_fallback(): void
    {
        [$profile, $signals] = $this->makeInputs();
        $out = $this->service->resolve($profile, $signals, []);

        $this->assertSame('base', $out['block_type']);
        $this->assertSame('aerobic_base', $out['key_capability_focus']);
    }

    public function test_week_role_recovery_every_4th_week_in_base(): void
    {
        [$profile, $signals] = $this->makeInputs();
        // 3 ostatnie tygodnie tego samego bloku "base" → bieżący to 4.
        $recent = [
            ['week_start_date' => '2026-04-13', 'planned_total_min' => 240, 'block_type' => 'base'],
            ['week_start_date' => '2026-04-06', 'planned_total_min' => 240, 'block_type' => 'base'],
            ['week_start_date' => '2026-03-30', 'planned_total_min' => 240, 'block_type' => 'base'],
        ];
        $out = $this->service->resolve($profile, $signals, $recent);

        // weeks_in_block = 4 → recovery role
        $this->assertSame('base', $out['block_type']);
        $this->assertSame('recovery', $out['week_role']);
        $this->assertSame('decrease', $out['load_direction']);
    }

    public function test_load_direction_maintain_when_last_week_under_85pct(): void
    {
        [$profile, $signals] = $this->makeInputs();
        $recent = [
            ['week_start_date' => '2026-04-13', 'planned_total_min' => 300, 'actual_total_min' => 200, 'block_type' => 'base'],
        ];
        $out = $this->service->resolve($profile, $signals, $recent);

        $this->assertSame('maintain', $out['load_direction']);
    }

    public function test_load_direction_increase_when_last_week_at_or_above_85pct(): void
    {
        [$profile, $signals] = $this->makeInputs();
        $recent = [
            ['week_start_date' => '2026-04-13', 'planned_total_min' => 300, 'actual_total_min' => 270, 'block_type' => 'base'],
        ];
        $out = $this->service->resolve($profile, $signals, $recent);

        $this->assertSame('increase', $out['load_direction']);
    }

    public function test_focus_threshold_in_build_after_3_plus_base_weeks(): void
    {
        [$profile, $signals] = $this->makeInputs();
        // Load trend up → build. Recent: 3 tygodnie base, rosnący trend.
        $recent = [
            ['week_start_date' => '2026-04-13', 'planned_total_min' => 300, 'block_type' => 'base'],
            ['week_start_date' => '2026-04-06', 'planned_total_min' => 270, 'block_type' => 'base'],
            ['week_start_date' => '2026-03-30', 'planned_total_min' => 240, 'block_type' => 'base'],
        ];
        $out = $this->service->resolve($profile, $signals, $recent);

        $this->assertSame('build', $out['block_type']);
        $this->assertSame('threshold', $out['key_capability_focus']);
    }

    public function test_taper_overrides_injury_risk(): void
    {
        [$profile, $signals] = $this->makeInputs([
            'primaryRace' => ['date' => '2026-04-27', 'distanceKm' => 42.2, 'priority' => 'A'],
        ], [
            'flags' => ['injuryRisk' => true],
        ]);
        $out = $this->service->resolve($profile, $signals, []);

        // Priority: weeksUntilRace wygrywa nad injuryRisk
        $this->assertSame('taper', $out['block_type']);
    }
}
