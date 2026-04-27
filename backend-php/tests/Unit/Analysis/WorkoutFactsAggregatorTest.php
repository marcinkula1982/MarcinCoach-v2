<?php

namespace Tests\Unit\Analysis;

use App\Services\Analysis\WorkoutFactsAggregator;
use App\Services\Analysis\ActivityImpactService;
use App\Support\Analysis\Dto\WorkoutFactsDto;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WorkoutFactsAggregatorTest extends TestCase
{
    public function test_empty_input_returns_zeroed_aggregates(): void
    {
        $now = CarbonImmutable::create(2026, 4, 26, 12, 0, 0, 'UTC');
        $result = (new WorkoutFactsAggregator)->aggregate([], $now);

        $this->assertSame(0, $result['workoutCount']);
        $this->assertNull($result['lastWorkoutAt']);
        $this->assertNull($result['load7d']);
        $this->assertNull($result['runningLoad7d']);
        $this->assertNull($result['crossTrainingFatigue7d']);
        $this->assertNull($result['overallFatigue7d']);
        $this->assertNull($result['acwr']);
        $this->assertFalse($result['spikeLoad']);
        $this->assertFalse($result['overallFatigueSpike']);
        $this->assertSame([], $result['gaps']);
    }

    public function test_steady_user_has_no_spike_load(): void
    {
        $now = CarbonImmutable::create(2026, 4, 26, 12, 0, 0, 'UTC');
        // 4 tygodnie, po 3 treningi tygodniowo, kazdy 60 min, 10 km
        $facts = [];
        for ($week = 0; $week < 4; $week++) {
            for ($day = 0; $day < 3; $day++) {
                $at = $now->subDays($week * 7 + $day * 2 + 1);
                $facts[] = $this->fact($at, durationSec: 3600, distanceMeters: 10000);
            }
        }

        $result = (new WorkoutFactsAggregator)->aggregate($facts, $now);

        $this->assertSame(12, $result['workoutCount']);
        $this->assertGreaterThan(0, $result['load7d']);
        $this->assertGreaterThan(0, $result['load28d']);
        $this->assertNotNull($result['acwr']);
        $this->assertLessThanOrEqual(WorkoutFactsAggregator::SPIKE_ACWR_THRESHOLD, $result['acwr']);
        $this->assertFalse($result['spikeLoad']);
    }

    public function test_spike_load_triggers_when_acwr_above_threshold(): void
    {
        $now = CarbonImmutable::create(2026, 4, 26, 12, 0, 0, 'UTC');
        $facts = [];
        // bardzo niska baza w starszych 3 tygodniach (1 trening 30 min na tydzien)
        for ($week = 1; $week <= 3; $week++) {
            $facts[] = $this->fact($now->subDays($week * 7 + 1), durationSec: 1800, distanceMeters: 4000);
        }
        // ostry skok w ostatnim tygodniu (4 dlugie treningi)
        for ($i = 0; $i < 4; $i++) {
            $facts[] = $this->fact($now->subDays($i + 1), durationSec: 5400, distanceMeters: 15000);
        }

        $result = (new WorkoutFactsAggregator)->aggregate($facts, $now);

        $this->assertNotNull($result['acwr']);
        $this->assertGreaterThan(WorkoutFactsAggregator::SPIKE_ACWR_THRESHOLD, $result['acwr']);
        $this->assertTrue($result['spikeLoad']);
    }

    public function test_long_gap_appears_in_gaps_and_longest_gap_days(): void
    {
        $now = CarbonImmutable::create(2026, 4, 26, 12, 0, 0, 'UTC');
        $facts = [
            $this->fact($now->subDays(80), durationSec: 3600, distanceMeters: 10000),
            // przerwa 60 dni
            $this->fact($now->subDays(20), durationSec: 3600, distanceMeters: 10000),
            $this->fact($now->subDays(10), durationSec: 3600, distanceMeters: 10000),
        ];

        $result = (new WorkoutFactsAggregator)->aggregate($facts, $now);

        $this->assertNotEmpty($result['gaps']);
        $this->assertSame(60, $result['gaps'][0]['days']);
        $this->assertGreaterThanOrEqual(60, $result['longestGapDays']);
    }

    public function test_weighted_avg_pace_uses_distance_weights(): void
    {
        $now = CarbonImmutable::create(2026, 4, 26, 12, 0, 0, 'UTC');
        // dwa biegi: szybki 5km w 25min (300 s/km) i wolny 15km w 90min (360 s/km)
        $facts = [
            $this->fact($now->subDays(5), durationSec: 25 * 60, distanceMeters: 5000, sport: 'run'),
            $this->fact($now->subDays(2), durationSec: 90 * 60, distanceMeters: 15000, sport: 'run'),
        ];

        $result = (new WorkoutFactsAggregator)->aggregate($facts, $now);

        // total: (25+90)*60 / ((5000+15000)/1000) = 115*60 / 20 = 345 s/km
        $this->assertSame(345.0, $result['avgPaceSecPerKm']);
    }

    public function test_max_hr_observed_picks_highest_max(): void
    {
        $now = CarbonImmutable::create(2026, 4, 26, 12, 0, 0, 'UTC');
        $facts = [
            $this->fact($now->subDays(5), maxHr: 170.0, avgHr: 140.0),
            $this->fact($now->subDays(3), maxHr: 188.0, avgHr: 150.0),
            $this->fact($now->subDays(1), maxHr: 175.0, avgHr: 145.0),
        ];

        $result = (new WorkoutFactsAggregator)->aggregate($facts, $now);

        $this->assertSame(188.0, $result['maxHrObservedBpm']);
        $this->assertSame(round((140.0 + 150.0 + 145.0) / 3, 2), $result['avgHrBpm']);
    }

    public function test_consistency_score_reflects_weeks_with_workouts(): void
    {
        $now = CarbonImmutable::create(2026, 4, 26, 12, 0, 0, 'UTC');
        // tylko 2 tygodnie z 4 maja trening
        $facts = [
            $this->fact($now->subDays(2), durationSec: 3600, distanceMeters: 10000),
            $this->fact($now->subDays(8), durationSec: 3600, distanceMeters: 10000),
        ];

        $result = (new WorkoutFactsAggregator)->aggregate($facts, $now);

        $this->assertNotNull($result['consistencyScore']);
        $this->assertSame(0.5, $result['consistencyScore']);
    }

    public function test_running_and_cross_training_loads_are_reported_separately(): void
    {
        $now = CarbonImmutable::create(2026, 4, 26, 12, 0, 0, 'UTC');
        $impact = new ActivityImpactService();
        $facts = [
            $this->fact($now->subDays(1), durationSec: 60 * 60, distanceMeters: 10000, sport: 'run'),
            $this->fact(
                $now->subDays(2),
                durationSec: 3 * 60 * 60,
                distanceMeters: 0,
                sport: 'strength',
                sportSubtype: 'lower_body',
                activityImpact: $impact->impact('strength', 'lower_body', 3 * 60 * 60, null, null, ['intensity' => 'hard']),
            ),
            $this->fact(
                $now->subDays(3),
                durationSec: 30 * 60,
                distanceMeters: 0,
                sport: 'bike',
                activityImpact: $impact->impact('bike', null, 30 * 60, null, null, ['intensity' => 'easy']),
            ),
        ];

        $result = (new WorkoutFactsAggregator)->aggregate($facts, $now);

        $this->assertSame(60.0, $result['runningLoad7d']);
        $this->assertSame(60.0, $result['load7d']);
        $this->assertSame(197.7, $result['crossTrainingFatigue7d']);
        $this->assertSame(257.7, $result['overallFatigue7d']);
        $this->assertSame(60.0, $result['runningLoad28d']);
        $this->assertSame(197.7, $result['crossTrainingFatigue28d']);
        $this->assertSame(257.7, $result['overallFatigue28d']);
        $this->assertNotNull($result['acwrRunning']);
        $this->assertNotNull($result['acwrOverall']);
    }

    private function fact(
        CarbonImmutable $at,
        ?int $durationSec = 3600,
        ?float $distanceMeters = 10000.0,
        string $sport = 'run',
        ?float $maxHr = null,
        ?float $avgHr = null,
        ?string $sportSubtype = null,
        array $activityImpact = [],
    ): WorkoutFactsDto {
        static $counter = 0;
        $counter++;

        return new WorkoutFactsDto(
            workoutId: (string) $counter,
            userId: '1',
            source: 'manual',
            sourceActivityId: null,
            startedAt: $at->toIso8601String(),
            durationSec: $durationSec,
            movingTimeSec: null,
            distanceMeters: $distanceMeters,
            sportKind: $sport,
            hasGps: false,
            hasHr: $avgHr !== null || $maxHr !== null,
            hasCadence: false,
            hasPower: false,
            hasElevation: false,
            avgPaceSecPerKm: null,
            avgHrBpm: $avgHr,
            maxHrBpm: $maxHr,
            hrSampleCount: 0,
            elevationGainMeters: null,
            perceivedEffort: null,
            notes: null,
            rawProviderRefs: ['rawTcxId' => null, 'rawFitId' => null, 'rawProviderPayloadId' => null],
            computedAt: $at->toIso8601String(),
            extractorVersion: '1.0',
            sportSubtype: $sportSubtype,
            activityImpact: $activityImpact,
        );
    }
}
