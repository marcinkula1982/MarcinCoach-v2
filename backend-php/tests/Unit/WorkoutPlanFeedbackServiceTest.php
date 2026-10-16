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

    public function test_newest_plan_version_before_workout_wins_easy30_easy40_rest(): void
    {
        Carbon::setTestNow('2026-10-15T12:00:00Z');

        try {
            $this->insertSnapshot('2026-10-12', '2026-10-18', [
                ['dateIso' => '2026-10-15', 'type' => 'easy', 'durationMin' => 30, 'intensityHint' => 'Z2'],
            ], '2026-10-10 08:00:00');
            $this->insertSnapshot('2026-10-12', '2026-10-18', [
                ['dateIso' => '2026-10-15', 'type' => 'easy', 'durationMin' => 40, 'intensityHint' => 'Z2'],
            ], '2026-10-12 08:00:00');
            $workout = $this->workout($this->runSummary('2026-10-15T10:00:00Z', 40));

            $feedback = app(WorkoutPlanFeedbackService::class)->build($workout);

            $this->assertSame('matched', $feedback['planMatchStatus']);
            $this->assertSame(40, $feedback['planVsExecution']['planned']['durationMin']);
            $this->assertSame([], $feedback['planVsExecution']['differences']);

            $this->insertSnapshot('2026-10-12', '2026-10-18', [
                ['dateIso' => '2026-10-15', 'type' => 'rest', 'durationMin' => 0],
            ], '2026-10-14 08:00:00');

            $feedback = app(WorkoutPlanFeedbackService::class)->build($workout);

            $this->assertSame('unplanned', $feedback['planMatchStatus']);
            $this->assertNull($feedback['planVsExecution']['planned']);
            $this->assertSame([], $feedback['planVsExecution']['differences']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_snapshot_created_after_workout_is_not_basis_for_assessment(): void
    {
        Carbon::setTestNow('2026-10-15T20:00:00Z');

        try {
            $this->insertSnapshot('2026-10-12', '2026-10-18', [
                ['dateIso' => '2026-10-15', 'type' => 'easy', 'durationMin' => 40, 'intensityHint' => 'Z2'],
            ], '2026-10-15 12:00:00');
            $workout = $this->workout($this->runSummary('2026-10-15T10:00:00Z', 40));

            $feedback = app(WorkoutPlanFeedbackService::class)->build($workout);

            $this->assertSame('no_plan', $feedback['planMatchStatus']);
            $this->assertNull($feedback['executionScore']);
            $this->assertNull($feedback['planVsExecution']['planned']);

            $this->insertSnapshot('2026-10-12', '2026-10-18', [
                ['dateIso' => '2026-10-15', 'type' => 'easy', 'durationMin' => 30, 'intensityHint' => 'Z2'],
            ], '2026-10-10 08:00:00');

            $feedback = app(WorkoutPlanFeedbackService::class)->build($workout);

            $this->assertSame(30, $feedback['planVsExecution']['planned']['durationMin']);
            $this->assertContains('duration_long_major', array_column($feedback['planVsExecution']['differences'], 'code'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_nearby_unmatched_session_without_plan_for_workout_day_is_no_plan(): void
    {
        Carbon::setTestNow('2026-10-15T12:00:00Z');

        try {
            $this->insertSnapshot('2026-10-13', '2026-10-13', [
                ['dateIso' => '2026-10-13', 'type' => 'easy', 'durationMin' => 20, 'intensityHint' => 'Z2'],
            ], '2026-10-12 08:00:00');
            $workout = $this->workout($this->runSummary('2026-10-15T10:00:00Z', 50));

            $feedback = app(WorkoutPlanFeedbackService::class)->build($workout);

            $this->assertSame('no_plan', $feedback['planMatchStatus']);
            $this->assertNull($feedback['executionScore']);
            $this->assertNull($feedback['planVsExecution']['planned']);
            $this->assertSame([], $feedback['planVsExecution']['differences']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_matching_delayed_session_is_missed_related_when_workout_day_has_no_plan(): void
    {
        Carbon::setTestNow('2026-10-15T12:00:00Z');

        try {
            $this->insertSnapshot('2026-10-13', '2026-10-13', [
                ['dateIso' => '2026-10-13', 'type' => 'easy', 'durationMin' => 50, 'intensityHint' => 'Z2'],
            ], '2026-10-12 08:00:00');
            $workout = $this->workout($this->runSummary('2026-10-15T10:00:00Z', 50));

            $feedback = app(WorkoutPlanFeedbackService::class)->build($workout);

            $this->assertSame('missed_related', $feedback['planMatchStatus']);
            $this->assertNotNull($feedback['executionScore']);
            $this->assertSame('2026-10-13', $feedback['planVsExecution']['planned']['dateIso']);
            $this->assertSame(50, $feedback['planVsExecution']['planned']['durationMin']);
            $this->assertContains('delayed_execution', array_column($feedback['planVsExecution']['differences'], 'code'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_explicit_rest_on_workout_day_is_unplanned_not_no_plan(): void
    {
        Carbon::setTestNow('2026-10-15T12:00:00Z');

        try {
            $this->insertSnapshot('2026-10-12', '2026-10-18', [
                ['dateIso' => '2026-10-15', 'type' => 'rest', 'durationMin' => 0],
            ], '2026-10-12 08:00:00');
            $workout = $this->workout($this->runSummary('2026-10-15T10:00:00Z', 50));

            $feedback = app(WorkoutPlanFeedbackService::class)->build($workout);

            $this->assertSame('unplanned', $feedback['planMatchStatus']);
            $this->assertNotNull($feedback['executionScore']);
            $this->assertNull($feedback['planVsExecution']['planned']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_missing_sessions_are_only_strictly_between_previous_and_current_run(): void
    {
        Carbon::setTestNow('2026-10-17T12:00:00Z');

        try {
            $sessions = [];
            for ($day = Carbon::parse('2026-10-12'); $day->toDateString() <= '2026-10-25'; $day->addDay()) {
                $sessions[] = $day->toDateString() === '2026-10-13'
                    ? ['dateIso' => '2026-10-13', 'type' => 'rest', 'durationMin' => 0]
                    : ['dateIso' => $day->toDateString(), 'type' => 'easy', 'durationMin' => 40, 'intensityHint' => 'Z2'];
            }
            $this->insertSnapshot('2026-10-12', '2026-10-25', $sessions, '2026-10-11 08:00:00');
            $this->workout($this->runSummary('2026-10-12T10:00:00Z', 40));
            $workout = $this->workout($this->runSummary('2026-10-15T10:00:00Z', 40));

            $feedback = app(WorkoutPlanFeedbackService::class)->build($workout);

            $missingDates = array_column($feedback['missingSessions'], 'dateIso');
            $this->assertSame(['2026-10-14'], $missingDates);
            $this->assertNotContains('2026-10-15', $missingDates);
            foreach (['2026-10-16', '2026-10-17', '2026-10-19', '2026-10-20', '2026-10-25'] as $futureOrLater) {
                $this->assertNotContains($futureOrLater, $missingDates);
            }
            $this->assertSame('missing_or_not_imported', $feedback['missingSessions'][0]['status']);
            $this->assertSame('matched', $feedback['planMatchStatus']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_missing_day_uses_plan_version_available_on_that_day(): void
    {
        Carbon::setTestNow('2026-10-17T12:00:00Z');

        try {
            $this->insertSnapshot('2026-10-12', '2026-10-25', [
                ['dateIso' => '2026-10-13', 'type' => 'easy', 'durationMin' => 40, 'intensityHint' => 'Z2'],
                ['dateIso' => '2026-10-14', 'type' => 'rest', 'durationMin' => 0],
            ], '2026-10-11 08:00:00');
            $this->insertSnapshot('2026-10-12', '2026-10-25', [
                ['dateIso' => '2026-10-13', 'type' => 'rest', 'durationMin' => 0],
                ['dateIso' => '2026-10-14', 'type' => 'easy', 'durationMin' => 50, 'intensityHint' => 'Z2'],
            ], '2026-10-15 08:00:00');
            $this->workout($this->runSummary('2026-10-12T10:00:00Z', 40));
            $workout = $this->workout($this->runSummary('2026-10-15T10:00:00Z', 40));

            $feedback = app(WorkoutPlanFeedbackService::class)->build($workout);

            $this->assertSame(['2026-10-13'], array_column($feedback['missingSessions'], 'dateIso'));
            $this->assertSame([40], array_column($feedback['missingSessions'], 'durationMin'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_two_real_sessions_on_one_day_of_selected_snapshot_are_kept(): void
    {
        Carbon::setTestNow('2026-10-17T12:00:00Z');

        try {
            $this->insertSnapshot('2026-10-12', '2026-10-25', [
                ['dateIso' => '2026-10-14', 'type' => 'easy', 'durationMin' => 50, 'intensityHint' => 'Z2'],
            ], '2026-10-09 08:00:00');
            $this->insertSnapshot('2026-10-12', '2026-10-25', [
                ['dateIso' => '2026-10-13', 'type' => 'rest', 'durationMin' => 0],
                ['dateIso' => '2026-10-14', 'type' => 'easy', 'durationMin' => 30, 'intensityHint' => 'Z2'],
                ['dateIso' => '2026-10-14', 'type' => 'easy', 'durationMin' => 20, 'intensityHint' => 'Z1'],
                ['dateIso' => '2026-10-15', 'type' => 'cross_training', 'durationMin' => 45, 'sportKind' => 'strength'],
                ['dateIso' => '2026-10-15', 'type' => 'easy', 'durationMin' => 40, 'intensityHint' => 'Z2'],
            ], '2026-10-11 08:00:00');
            $this->workout($this->runSummary('2026-10-12T10:00:00Z', 40));
            $workout = $this->workout($this->runSummary('2026-10-15T10:00:00Z', 40));

            $feedback = app(WorkoutPlanFeedbackService::class)->build($workout);

            $this->assertSame(['2026-10-14', '2026-10-14'], array_column($feedback['missingSessions'], 'dateIso'));
            $this->assertSame([30, 20], array_column($feedback['missingSessions'], 'durationMin'));
            $this->assertSame('matched', $feedback['planMatchStatus']);
            $this->assertSame('easy', $feedback['planVsExecution']['planned']['type']);
            $this->assertSame(40, $feedback['planVsExecution']['planned']['durationMin']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_identical_created_at_is_resolved_deterministically_by_highest_id(): void
    {
        Carbon::setTestNow('2026-10-15T12:00:00Z');

        try {
            $this->insertSnapshot('2026-10-12', '2026-10-18', [
                ['dateIso' => '2026-10-15', 'type' => 'easy', 'durationMin' => 50, 'intensityHint' => 'Z2'],
            ], '2026-10-11 08:00:00', id: 20);
            $this->insertSnapshot('2026-10-12', '2026-10-18', [
                ['dateIso' => '2026-10-15', 'type' => 'easy', 'durationMin' => 30, 'intensityHint' => 'Z2'],
            ], '2026-10-11 08:00:00', id: 10);
            $workout = $this->workout($this->runSummary('2026-10-15T10:00:00Z', 50));

            $first = app(WorkoutPlanFeedbackService::class)->build($workout);
            $second = app(WorkoutPlanFeedbackService::class)->build($workout);

            $this->assertSame(50, $first['planVsExecution']['planned']['durationMin']);
            $this->assertSame('matched', $first['planMatchStatus']);
            $this->assertSame($first['planVsExecution'], $second['planVsExecution']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_overload_risk_without_pain_is_described_as_load_signal_not_symptoms(): void
    {
        Carbon::setTestNow('2026-10-15T12:00:00Z');

        try {
            $this->insertSnapshot('2026-10-12', '2026-10-18', [
                ['dateIso' => '2026-10-15', 'type' => 'easy', 'durationMin' => 40, 'intensityHint' => 'Z2'],
            ], '2026-10-11 08:00:00');
            $signals = ['warnings' => ['overloadRisk' => true]];

            $feedback = app(WorkoutPlanFeedbackService::class)->build(
                $this->workout($this->runSummary('2026-10-15T10:00:00Z', 40)),
                [],
                [],
                $signals,
            );

            $this->assertContains('overload_risk', array_column($feedback['riskFlags'], 'code'));
            $this->assertNotContains('pain_flag', array_column($feedback['riskFlags'], 'code'));
            $this->assertSame('high', $feedback['planImpact']['level']);
            $this->assertStringContainsString('Sygnał obciążenia', $feedback['planImpact']['message']);
            $allText = mb_strtolower(implode(' ', array_merge(
                [$feedback['planImpact']['message'], $feedback['coachFeedback'], $feedback['nextStep']],
                $feedback['conclusions'],
                $feedback['deviations'],
            )));
            $this->assertStringNotContainsString('objaw', $allText);
            $this->assertStringNotContainsString('ból', $allText);

            $withPain = app(WorkoutPlanFeedbackService::class)->build(
                $this->workout($this->runSummary('2026-10-15T11:00:00Z', 40), ['painFlag' => true]),
                [],
                [],
                $signals,
            );

            $this->assertContains('pain_flag', array_column($withPain['riskFlags'], 'code'));
            $this->assertStringContainsString('objawy', $withPain['planImpact']['message']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_plan_and_missing_sessions_are_isolated_per_user(): void
    {
        Carbon::setTestNow('2026-10-17T12:00:00Z');

        try {
            User::create([
                'id' => 2,
                'name' => 'Other User',
                'email' => 'other@example.com',
                'password' => bcrypt('password'),
            ]);
            $this->insertSnapshot('2026-10-12', '2026-10-25', [
                ['dateIso' => '2026-10-14', 'type' => 'easy', 'durationMin' => 40, 'intensityHint' => 'Z2'],
                ['dateIso' => '2026-10-15', 'type' => 'easy', 'durationMin' => 40, 'intensityHint' => 'Z2'],
            ], '2026-10-11 08:00:00');
            $this->insertSnapshot('2026-10-12', '2026-10-25', [
                ['dateIso' => '2026-10-14', 'type' => 'rest', 'durationMin' => 0],
                ['dateIso' => '2026-10-15', 'type' => 'rest', 'durationMin' => 0],
            ], '2026-10-13 08:00:00', userId: 2);
            $this->workout($this->runSummary('2026-10-12T10:00:00Z', 40));
            $userOneRun = $this->workout($this->runSummary('2026-10-15T10:00:00Z', 40));
            $this->workout($this->runSummary('2026-10-14T10:00:00Z', 40), userId: 2);
            $userTwoRun = $this->workout($this->runSummary('2026-10-15T10:00:00Z', 40), userId: 2);

            $userOne = app(WorkoutPlanFeedbackService::class)->build($userOneRun);
            $userTwo = app(WorkoutPlanFeedbackService::class)->build($userTwoRun);

            $this->assertSame('matched', $userOne['planMatchStatus']);
            $this->assertSame(['2026-10-14'], array_column($userOne['missingSessions'], 'dateIso'));
            $this->assertSame('unplanned', $userTwo['planMatchStatus']);
            $this->assertNull($userTwo['planVsExecution']['planned']);
            $this->assertSame([], $userTwo['missingSessions']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function runSummary(string $startTimeIso, int $durationMin): array
    {
        $durationSec = $durationMin * 60;

        return [
            'startTimeIso' => $startTimeIso,
            'durationSec' => $durationSec,
            'movingTimeSec' => $durationSec,
            'distanceM' => $durationMin * 165,
            'sport' => 'run',
            'intensityBuckets' => ['z1Sec' => 0, 'z2Sec' => $durationSec, 'z3Sec' => 0, 'z4Sec' => 0, 'z5Sec' => 0],
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $meta
     */
    private function workout(array $summary, array $meta = [], int $userId = 1): Workout
    {
        $key = sha1($userId.'|'.json_encode($summary).'|'.json_encode($meta));

        return Workout::create([
            'user_id' => $userId,
            'action' => 'save',
            'kind' => 'training',
            'summary' => $summary,
            'workout_meta' => $meta,
            'source' => 'MANUAL_UPLOAD',
            'source_activity_id' => 'workout-plan-feedback-'.$key,
            'dedupe_key' => 'MANUAL_UPLOAD:'.$key,
        ]);
    }

    /**
     * @param list<array<string,mixed>> $sessions
     */
    private function insertPlanSnapshot(string $dateIso, array $sessions): void
    {
        $this->insertSnapshot(
            $dateIso,
            $dateIso,
            $sessions,
            Carbon::parse($dateIso)->subDay()->startOfDay()->toDateTimeString(),
        );
    }

    /**
     * @param list<array<string,mixed>> $sessions
     */
    private function insertSnapshot(
        string $windowStartDate,
        string $windowEndDate,
        array $sessions,
        string $createdAt,
        ?int $id = null,
        int $userId = 1,
    ): void {
        $windowStart = Carbon::parse($windowStartDate)->startOfDay()->toISOString();
        $windowEnd = Carbon::parse($windowEndDate)->endOfDay()->toISOString();

        DB::table('plan_snapshots')->insert(array_filter([
            'id' => $id,
            'user_id' => $userId,
            'snapshot_json' => json_encode([
                'windowStartIso' => $windowStart,
                'windowEndIso' => $windowEnd,
                'sessions' => $sessions,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'window_start_iso' => $windowStart,
            'window_end_iso' => $windowEnd,
            'created_at' => $createdAt,
        ], fn ($value) => $value !== null));
    }
}
