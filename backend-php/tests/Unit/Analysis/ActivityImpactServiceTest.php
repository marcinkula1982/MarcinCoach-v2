<?php

namespace Tests\Unit\Analysis;

use App\Services\Analysis\ActivityImpactService;
use PHPUnit\Framework\TestCase;

class ActivityImpactServiceTest extends TestCase
{
    public function test_hard_lower_body_strength_has_high_fatigue_and_collision(): void
    {
        $impact = (new ActivityImpactService())->impact(
            'strength',
            'lower_body',
            3 * 60 * 60,
            null,
            null,
            ['intensity' => 'hard'],
        );

        $this->assertSame(0.0, $impact['runningLoadMin']);
        $this->assertSame(187.2, $impact['crossTrainingFatigueMin']);
        $this->assertSame(187.2, $impact['overallFatigueMin']);
        $this->assertSame('high', $impact['collisionLevel']);
        $this->assertContains('lower_body', $impact['affectedSystems']);
    }

    public function test_easy_thirty_minute_bike_has_low_fatigue(): void
    {
        $impact = (new ActivityImpactService())->impact(
            'bike',
            null,
            30 * 60,
            null,
            null,
            ['intensity' => 'easy'],
        );

        $this->assertSame(0.0, $impact['runningLoadMin']);
        $this->assertSame(10.5, $impact['crossTrainingFatigueMin']);
        $this->assertSame(10.5, $impact['overallFatigueMin']);
        $this->assertSame('low', $impact['collisionLevel']);
    }
}
