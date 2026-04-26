<?php

namespace Tests\Unit\Analysis;

use App\Services\Analysis\PlanImplicationsResolver;
use App\Support\Analysis\Dto\HrZonesDto;
use App\Support\Analysis\Enums\HrZoneStatus;
use PHPUnit\Framework\TestCase;

class PlanImplicationsResolverTest extends TestCase
{
    public function test_cold_start_when_no_workouts(): void
    {
        $result = (new PlanImplicationsResolver)->resolve(
            $this->facts(workoutCount: 0),
            HrZonesDto::missing(),
        );

        $this->assertContainsCode('no_workouts_in_window', $result['missingData']);
        $this->assertContainsCode('cold_start', $result['planImplications']);
        $this->assertSame('low', $result['confidence']['overall']);
    }

    public function test_high_confidence_with_8_plus_workouts_and_known_zones(): void
    {
        $hrZones = new HrZonesDto(
            status: HrZoneStatus::Known,
            method: 'user_provided',
            sourceNote: 'test',
            zones: [
                ['name' => 'Z1', 'minBpm' => 100, 'maxBpm' => 120],
                ['name' => 'Z2', 'minBpm' => 120, 'maxBpm' => 140],
                ['name' => 'Z3', 'minBpm' => 140, 'maxBpm' => 160],
                ['name' => 'Z4', 'minBpm' => 160, 'maxBpm' => 175],
                ['name' => 'Z5', 'minBpm' => 175, 'maxBpm' => 195],
            ],
        );

        $result = (new PlanImplicationsResolver)->resolve(
            $this->facts(
                workoutCount: 20,
                workoutCount28d: 10,
                avgPaceSecPerKm: 350.0,
                avgHrBpm: 145.0,
                load7d: 200.0,
                consistencyScore: 1.0,
            ),
            $hrZones,
        );

        $this->assertSame('high', $result['confidence']['overall']);
        $this->assertSame('high', $result['confidence']['perField']['hrZones']);
        $this->assertSame('high', $result['confidence']['perField']['load7d']);
    }

    public function test_return_after_break_signal(): void
    {
        $result = (new PlanImplicationsResolver)->resolve(
            $this->facts(
                workoutCount: 5,
                workoutCount28d: 1,
                lastWorkoutWasDaysAgo: 21,
            ),
            HrZonesDto::missing(),
        );

        $this->assertContainsCode('return_after_break', $result['planImplications']);
    }

    public function test_load_spike_blocks_with_block_severity(): void
    {
        $result = (new PlanImplicationsResolver)->resolve(
            $this->facts(
                workoutCount: 12,
                workoutCount28d: 8,
                acwr: 1.8,
                spikeLoad: true,
            ),
            HrZonesDto::missing(),
        );

        $hits = array_filter($result['planImplications'], fn ($i) => $i['code'] === 'load_spike');
        $this->assertNotEmpty($hits);
        $this->assertSame('block', array_values($hits)[0]['severity']);
    }

    public function test_estimated_zones_emit_info_implication(): void
    {
        $hrZones = new HrZonesDto(
            status: HrZoneStatus::Estimated,
            method: 'max_hr_percent',
            sourceNote: 'test',
            zones: [
                ['name' => 'Z1', 'minBpm' => 90, 'maxBpm' => 110],
                ['name' => 'Z2', 'minBpm' => 110, 'maxBpm' => 130],
                ['name' => 'Z3', 'minBpm' => 130, 'maxBpm' => 150],
                ['name' => 'Z4', 'minBpm' => 150, 'maxBpm' => 170],
                ['name' => 'Z5', 'minBpm' => 170, 'maxBpm' => 190],
            ],
        );

        $result = (new PlanImplicationsResolver)->resolve(
            $this->facts(workoutCount: 5, workoutCount28d: 5),
            $hrZones,
        );

        $this->assertContainsCode('hr_zones_estimated', $result['missingData']);
        $this->assertContainsCode('zones_estimated_only', $result['planImplications']);
        $this->assertSame('low', $result['confidence']['perField']['hrZones']);
    }

    /**
     * @return array<string,mixed>
     */
    private function facts(
        int $workoutCount = 0,
        int $workoutCount28d = 0,
        ?int $lastWorkoutWasDaysAgo = null,
        ?float $avgPaceSecPerKm = null,
        ?float $avgHrBpm = null,
        ?float $load7d = null,
        ?float $acwr = null,
        bool $spikeLoad = false,
        ?float $consistencyScore = null,
    ): array {
        return [
            'workoutCount' => $workoutCount,
            'workoutCount28d' => $workoutCount28d,
            'lastWorkoutWasDaysAgo' => $lastWorkoutWasDaysAgo,
            'avgPaceSecPerKm' => $avgPaceSecPerKm,
            'avgHrBpm' => $avgHrBpm,
            'load7d' => $load7d,
            'acwr' => $acwr,
            'spikeLoad' => $spikeLoad,
            'consistencyScore' => $consistencyScore,
        ];
    }

    /**
     * @param  list<array{code:string}>  $items
     */
    private function assertContainsCode(string $code, array $items): void
    {
        $codes = array_column($items, 'code');
        $this->assertContains($code, $codes, "Expected code '{$code}' in: ".implode(',', $codes));
    }
}
