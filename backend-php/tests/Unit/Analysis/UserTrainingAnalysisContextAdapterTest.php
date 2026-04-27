<?php

namespace Tests\Unit\Analysis;

use App\Services\Analysis\UserTrainingAnalysisContextAdapter;
use PHPUnit\Framework\TestCase;

class UserTrainingAnalysisContextAdapterTest extends TestCase
{
    public function test_maps_training_analysis_facts_to_weekly_plan_signals(): void
    {
        $signals = (new UserTrainingAnalysisContextAdapter)->toSignals([
            'computedAt' => '2026-04-27T10:00:00Z',
            'windowDays' => 28,
            'facts' => [
                'workoutCount' => 4,
                'load7d' => 90.5,
                'load28d' => 310.25,
                'longestRunMeters' => 12500,
                'spikeLoad' => true,
            ],
            'planImplications' => [
                ['code' => 'load_spike', 'severity' => 'block'],
            ],
        ]);

        $this->assertSame(28, $signals['windowDays']);
        $this->assertSame(90.5, $signals['weeklyLoad']);
        $this->assertSame(310.25, $signals['rolling4wLoad']);
        $this->assertSame(4, $signals['totalWorkouts']);
        $this->assertTrue($signals['longRun']['exists']);
        $this->assertSame(12.5, $signals['longRun']['distanceKm']);
        $this->assertTrue($signals['flags']['fatigue']);
        $this->assertTrue($signals['flags']['loadSpike']);
        $this->assertFalse($signals['flags']['injuryRisk']);
    }

    public function test_keeps_legacy_compatibility_fields_until_m4_is_migrated(): void
    {
        $signals = (new UserTrainingAnalysisContextAdapter)->toSignals([
            'computedAt' => '2026-04-27T10:00:00Z',
            'windowDays' => 28,
            'facts' => [
                'workoutCount' => 2,
                'load7d' => null,
                'load28d' => null,
                'longestRunMeters' => null,
                'spikeLoad' => false,
            ],
            'planImplications' => [],
        ], [
            'buckets' => [
                'z1Sec' => 1200,
                'z2Sec' => 1800,
                'z3Sec' => 0,
                'z4Sec' => 0,
                'z5Sec' => 0,
                'totalSec' => 3000,
            ],
            'longRun' => [
                'exists' => true,
                'distanceKm' => 8.2,
                'workoutId' => 15,
                'workoutDt' => '2026-04-24T10:00:00Z',
            ],
            'flags' => [
                'injuryRisk' => true,
                'fatigue' => false,
            ],
            'adaptation' => [
                'missedKeyWorkout' => false,
                'harderThanPlanned' => false,
                'easierThanPlannedStreak' => 2,
                'controlStartRecent' => false,
            ],
        ]);

        $this->assertSame(0.0, $signals['weeklyLoad']);
        $this->assertSame(0.0, $signals['rolling4wLoad']);
        $this->assertSame(3000.0, $signals['buckets']['totalSec']);
        $this->assertSame(8.2, $signals['longRun']['distanceKm']);
        $this->assertSame(15, $signals['longRun']['workoutId']);
        $this->assertTrue($signals['flags']['injuryRisk']);
        $this->assertSame(2, $signals['adaptation']['easierThanPlannedStreak']);
    }
}
