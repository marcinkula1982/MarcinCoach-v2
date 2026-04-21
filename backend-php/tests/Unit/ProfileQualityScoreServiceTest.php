<?php

namespace Tests\Unit;

use App\Services\ProfileQualityScoreService;
use Tests\TestCase;

class ProfileQualityScoreServiceTest extends TestCase
{
    private ProfileQualityScoreService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ProfileQualityScoreService();
    }

    public function test_empty_profile_scores_zero(): void
    {
        $result = $this->svc->scoreWithBreakdown([]);

        $this->assertSame(0, $result['score']);
        foreach ($result['breakdown'] as $key => $item) {
            $this->assertFalse($item['ok'], "Expected {$key} to be not ok on empty profile");
            $this->assertSame(0, $item['points']);
        }
    }

    public function test_running_days_from_availability_json(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'availability_json' => ['runningDays' => ['mon', 'wed', 'fri']],
        ]);

        $this->assertTrue($result['breakdown']['runningDays']['ok']);
        $this->assertSame(15, $result['breakdown']['runningDays']['points']);
    }

    public function test_running_days_from_preferred_run_days_fallback(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'preferred_run_days' => '[1,3,5]',
        ]);

        $this->assertTrue($result['breakdown']['runningDays']['ok']);
        $this->assertSame(15, $result['breakdown']['runningDays']['points']);
    }

    public function test_primary_race_from_projection_column(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'primary_race_date' => now()->addDays(60)->toDateString(),
        ]);

        $this->assertTrue($result['breakdown']['primaryRace']['ok']);
        $this->assertSame(20, $result['breakdown']['primaryRace']['points']);
    }

    public function test_primary_race_from_races_json(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'races_json' => [
                ['date' => now()->addDays(90)->toDateString(), 'distanceKm' => 42.2, 'priority' => 'A'],
            ],
        ]);

        $this->assertTrue($result['breakdown']['primaryRace']['ok']);
    }

    public function test_past_race_does_not_count(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'races_json' => [
                ['date' => '2020-01-01', 'distanceKm' => 42.2, 'priority' => 'A'],
            ],
        ]);

        $this->assertFalse($result['breakdown']['primaryRace']['ok']);
    }

    public function test_max_session_min_scored(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'max_session_min' => 75,
        ]);

        $this->assertTrue($result['breakdown']['maxSessionMin']['ok']);
        $this->assertSame(15, $result['breakdown']['maxSessionMin']['points']);
    }

    public function test_max_session_min_from_availability_json_fallback(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'availability_json' => ['runningDays' => ['mon'], 'maxSessionMin' => 60],
        ]);

        $this->assertTrue($result['breakdown']['maxSessionMin']['ok']);
    }

    public function test_max_session_min_out_of_range_not_scored(): void
    {
        $this->assertFalse(
            $this->svc->scoreWithBreakdown(['max_session_min' => 5])['breakdown']['maxSessionMin']['ok']
        );
        $this->assertFalse(
            $this->svc->scoreWithBreakdown(['max_session_min' => 400])['breakdown']['maxSessionMin']['ok']
        );
    }

    public function test_health_scored_when_complete(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'health_json' => ['injuryHistory' => [], 'currentPain' => false],
        ]);

        $this->assertTrue($result['breakdown']['health']['ok']);
        $this->assertSame(10, $result['breakdown']['health']['points']);
    }

    public function test_health_not_scored_when_currentPain_missing(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'health_json' => ['injuryHistory' => []],
        ]);

        $this->assertFalse($result['breakdown']['health']['ok']);
    }

    public function test_equipment_scored_when_complete(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'equipment_json' => ['watch' => true, 'hrSensor' => false],
        ]);

        $this->assertTrue($result['breakdown']['equipment']['ok']);
        $this->assertSame(10, $result['breakdown']['equipment']['points']);
    }

    public function test_equipment_not_scored_when_partial(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'equipment_json' => ['watch' => true],
        ]);

        $this->assertFalse($result['breakdown']['equipment']['ok']);
    }

    public function test_hr_zones_complete_and_consistent(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'hr_z1_min' => 100, 'hr_z1_max' => 120,
            'hr_z2_min' => 121, 'hr_z2_max' => 140,
            'hr_z3_min' => 141, 'hr_z3_max' => 160,
            'hr_z4_min' => 161, 'hr_z4_max' => 175,
            'hr_z5_min' => 176, 'hr_z5_max' => 195,
        ]);

        $this->assertTrue($result['breakdown']['hrZones']['ok']);
        $this->assertSame(20, $result['breakdown']['hrZones']['points']);
    }

    public function test_hr_zones_incomplete_not_scored(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'hr_z1_min' => 100, 'hr_z1_max' => 120,
            // z2-z5 missing
        ]);

        $this->assertFalse($result['breakdown']['hrZones']['ok']);
    }

    public function test_hr_zones_min_not_less_than_max_not_scored(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'hr_z1_min' => 120, 'hr_z1_max' => 120, // equal — invalid
            'hr_z2_min' => 121, 'hr_z2_max' => 140,
            'hr_z3_min' => 141, 'hr_z3_max' => 160,
            'hr_z4_min' => 161, 'hr_z4_max' => 175,
            'hr_z5_min' => 176, 'hr_z5_max' => 195,
        ]);

        $this->assertFalse($result['breakdown']['hrZones']['ok']);
    }

    public function test_hr_zones_non_monotonic_not_scored(): void
    {
        $result = $this->svc->scoreWithBreakdown([
            'hr_z1_min' => 100, 'hr_z1_max' => 130, // z1.max > z2.min
            'hr_z2_min' => 121, 'hr_z2_max' => 140,
            'hr_z3_min' => 141, 'hr_z3_max' => 160,
            'hr_z4_min' => 161, 'hr_z4_max' => 175,
            'hr_z5_min' => 176, 'hr_z5_max' => 195,
        ]);

        $this->assertFalse($result['breakdown']['hrZones']['ok']);
    }

    public function test_surface_scored_when_set(): void
    {
        $result = $this->svc->scoreWithBreakdown(['preferred_surface' => 'TRAIL']);

        $this->assertTrue($result['breakdown']['surface']['ok']);
        $this->assertSame(10, $result['breakdown']['surface']['points']);
    }

    public function test_full_profile_scores_100(): void
    {
        $data = [
            'availability_json' => ['runningDays' => ['mon', 'wed', 'fri'], 'maxSessionMin' => 75],
            'races_json' => [['date' => now()->addMonths(3)->toDateString(), 'distanceKm' => 21.1, 'priority' => 'A']],
            'max_session_min' => 75,
            'health_json' => ['injuryHistory' => [], 'currentPain' => false],
            'equipment_json' => ['watch' => true, 'hrSensor' => true],
            'hr_z1_min' => 100, 'hr_z1_max' => 120,
            'hr_z2_min' => 121, 'hr_z2_max' => 140,
            'hr_z3_min' => 141, 'hr_z3_max' => 160,
            'hr_z4_min' => 161, 'hr_z4_max' => 175,
            'hr_z5_min' => 176, 'hr_z5_max' => 195,
            'preferred_surface' => 'TRAIL',
        ];

        $result = $this->svc->scoreWithBreakdown($data);

        $this->assertSame(100, $result['score']);
        foreach ($result['breakdown'] as $key => $item) {
            $this->assertTrue($item['ok'], "Expected {$key} to be ok on full profile");
        }
    }

    public function test_score_method_returns_integer(): void
    {
        $this->assertIsInt($this->svc->score(['preferred_surface' => 'ROAD']));
    }

    public function test_breakdown_max_sums_to_100(): void
    {
        $result = $this->svc->scoreWithBreakdown([]);
        $totalMax = array_sum(array_column($result['breakdown'], 'max'));
        $this->assertSame(100, $totalMax);
    }
}
