<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Workout;
use App\Services\WorkoutPlanFeedbackService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkoutPlanFeedbackServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_historical_without_plan_has_null_execution_score(): void
    {
        Carbon::setTestNow('2026-04-30T12:00:00Z');

        try {
            $feedback = app(WorkoutPlanFeedbackService::class)->build($this->workout([
                'startTimeIso' => '2026-02-14T10:00:00Z',
                'durationSec' => 1800,
                'movingTimeSec' => 1800,
                'distanceM' => 5000,
                'sport' => 'run',
            ]));

            $this->assertSame('historical', $feedback['planMatchStatus']);
            $this->assertNull($feedback['executionScore']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_no_plan_has_null_execution_score(): void
    {
        Carbon::setTestNow('2026-04-30T12:00:00Z');

        try {
            $feedback = app(WorkoutPlanFeedbackService::class)->build($this->workout([
                'startTimeIso' => '2026-04-30T10:00:00Z',
                'durationSec' => 1800,
                'movingTimeSec' => 1800,
                'distanceM' => 5000,
                'sport' => 'run',
            ]));

            $this->assertSame('no_plan', $feedback['planMatchStatus']);
            $this->assertNull($feedback['executionScore']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_non_running_sport_on_running_plan_is_not_partial(): void
    {
        Carbon::setTestNow('2026-04-30T12:00:00Z');

        try {
            $this->insertPlanSnapshot('2026-04-30', [
                ['dateIso' => '2026-04-30', 'type' => 'easy', 'durationMin' => 30, 'intensityHint' => 'Z2'],
            ]);

            $feedback = app(WorkoutPlanFeedbackService::class)->build($this->workout([
                'startTimeIso' => '2026-04-30T10:00:00Z',
                'durationSec' => 1800,
                'movingTimeSec' => 1800,
                'distanceM' => 12000,
                'sport' => 'bike',
            ]));

            $this->assertSame('unplanned', $feedback['planMatchStatus']);
            $this->assertNotSame('partial', $feedback['planMatchStatus']);
            $this->assertContains('WRONG_SPORT', array_column($feedback['riskFlags'], 'code'));
            $this->assertStringContainsString('nie wykonuje jednostki biegowej z planu', $feedback['coachFeedback']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_old_workout_with_plan_snapshot_is_assessed_against_that_plan(): void
    {
        Carbon::setTestNow('2026-04-30T12:00:00Z');

        try {
            $this->insertPlanSnapshot('2026-04-20', [
                ['dateIso' => '2026-04-20', 'type' => 'easy', 'durationMin' => 30, 'intensityHint' => 'Z2'],
            ]);

            $feedback = app(WorkoutPlanFeedbackService::class)->build($this->workout([
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 1800,
                'movingTimeSec' => 1800,
                'distanceM' => 5000,
                'sport' => 'run',
                'intensityBuckets' => ['z1Sec' => 0, 'z2Sec' => 1800, 'z3Sec' => 0, 'z4Sec' => 0, 'z5Sec' => 0],
            ]));

            $this->assertContains($feedback['planMatchStatus'], ['matched', 'partial']);
            $this->assertNotSame('historical', $feedback['planMatchStatus']);
            $this->assertNotNull($feedback['executionScore']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function workout(array $summary): Workout
    {
        return Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => $summary,
            'workout_meta' => [],
            'source' => 'MANUAL_UPLOAD',
            'source_activity_id' => 'workout-plan-feedback-'.sha1(json_encode($summary)),
            'dedupe_key' => 'MANUAL_UPLOAD:'.sha1(json_encode($summary)),
        ]);
    }

    /**
     * @param list<array<string,mixed>> $sessions
     */
    private function insertPlanSnapshot(string $dateIso, array $sessions): void
    {
        $windowStart = Carbon::parse($dateIso)->startOfDay()->toISOString();
        $windowEnd = Carbon::parse($dateIso)->endOfDay()->toISOString();

        DB::table('plan_snapshots')->insert([
            'user_id' => 1,
            'snapshot_json' => json_encode([
                'windowStartIso' => $windowStart,
                'windowEndIso' => $windowEnd,
                'sessions' => $sessions,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'window_start_iso' => $windowStart,
            'window_end_iso' => $windowEnd,
            'created_at' => now(),
        ]);
    }
}
