<?php

namespace Tests\Unit\Analysis;

use App\Models\User;
use App\Models\Workout;
use App\Services\Analysis\UserTrainingAnalysisService;
use App\Support\Analysis\Enums\HrZoneStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTrainingAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-26T12:00:00Z');

        User::create([
            'id' => 1,
            'name' => 'F3 User',
            'email' => 'f3@example.com',
            'password' => bcrypt('x'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_user_with_no_workouts_returns_low_confidence_and_cold_start(): void
    {
        $result = (new UserTrainingAnalysisService)->analyze(1)->toArray();

        $this->assertSame('1', $result['userId']);
        $this->assertSame(90, $result['windowDays']);
        $this->assertSame(UserTrainingAnalysisService::SERVICE_VERSION, $result['serviceVersion']);
        $this->assertSame(0, $result['facts']['workoutCount']);
        $this->assertSame('low', $result['confidence']['overall']);
        $this->assertSame(HrZoneStatus::Missing->value, $result['hrZones']['status']);

        $codes = array_column($result['planImplications'], 'code');
        $this->assertContains('cold_start', $codes);
    }

    public function test_user_with_recent_workouts_gets_real_aggregates(): void
    {
        // 8 treningow w 28d
        for ($i = 0; $i < 8; $i++) {
            Workout::create([
                'user_id' => 1,
                'action' => 'save',
                'kind' => 'training',
                'summary' => [
                    'startTimeIso' => Carbon::now()->subDays($i * 3 + 1)->toIso8601String(),
                    'durationSec' => 3600,
                    'distanceM' => 10000,
                    'sport' => 'run',
                    'hr' => ['avgBpm' => 145, 'maxBpm' => 175],
                    'avgPaceSecPerKm' => 360,
                ],
                'source' => 'garmin',
                'dedupe_key' => "f3-week-{$i}",
            ]);
        }

        $result = (new UserTrainingAnalysisService)->analyze(1)->toArray();

        $this->assertSame(8, $result['facts']['workoutCount']);
        $this->assertGreaterThan(0, $result['facts']['load28d']);
        $this->assertNotNull($result['facts']['avgPaceSecPerKm']);
        $this->assertSame(175.0, $result['facts']['maxHrObservedBpm']);
        // Strefy bez profilu, ale z observed max -> estimated
        $this->assertSame(HrZoneStatus::Estimated->value, $result['hrZones']['status']);
    }

    public function test_window_days_filters_old_workouts(): void
    {
        // jeden trening w oknie, jeden poza oknem (120 dni temu)
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => Carbon::now()->subDays(10)->toIso8601String(),
                'durationSec' => 3600,
                'distanceM' => 10000,
                'sport' => 'run',
            ],
            'source' => 'manual',
            'dedupe_key' => 'f3-recent',
        ]);
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => Carbon::now()->subDays(120)->toIso8601String(),
                'durationSec' => 3600,
                'distanceM' => 10000,
                'sport' => 'run',
            ],
            'source' => 'manual',
            'dedupe_key' => 'f3-old',
        ]);

        $result = (new UserTrainingAnalysisService)->analyze(1, 90)->toArray();

        $this->assertSame(1, $result['facts']['workoutCount']);
    }

    public function test_return_after_break_implication_appears(): void
    {
        // jedyny trening 21 dni temu
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => Carbon::now()->subDays(21)->toIso8601String(),
                'durationSec' => 3600,
                'distanceM' => 10000,
                'sport' => 'run',
            ],
            'source' => 'manual',
            'dedupe_key' => 'f3-break',
        ]);

        $result = (new UserTrainingAnalysisService)->analyze(1)->toArray();

        $codes = array_column($result['planImplications'], 'code');
        $this->assertContains('return_after_break', $codes);
    }
}
