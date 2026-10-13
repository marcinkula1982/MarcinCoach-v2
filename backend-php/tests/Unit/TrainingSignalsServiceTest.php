<?php

namespace Tests\Unit;

use App\Models\ManualCheckIn;
use App\Models\User;
use App\Services\TrainingSignalsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingSignalsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'id' => 1,
            'name' => 'Signals User',
            'email' => 'signals@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_user_without_check_ins_has_neutral_skip_signals(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21T12:00:00Z'));

        $adaptation = app(TrainingSignalsService::class)->getSignalsForUser(1)['adaptation'];

        $this->assertFalse($adaptation['missedKeyWorkout']);
        $this->assertSame(0, $adaptation['skippedSessions7d']);
        $this->assertSame([], $adaptation['skipReasons7d']);
    }

    public function test_skipped_key_session_in_last_7_days_sets_missed_key_workout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21T12:00:00Z'));

        $this->createSkip('2026-04-19', 'long', 'no_time');

        $adaptation = app(TrainingSignalsService::class)->getSignalsForUser(1)['adaptation'];

        $this->assertTrue($adaptation['missedKeyWorkout']);
        $this->assertSame(1, $adaptation['skippedSessions7d']);
        $this->assertSame(['no_time'], $adaptation['skipReasons7d']);
    }

    public function test_skipped_easy_session_does_not_set_missed_key_workout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21T12:00:00Z'));

        $this->createSkip('2026-04-19', 'easy', 'illness');

        $adaptation = app(TrainingSignalsService::class)->getSignalsForUser(1)['adaptation'];

        $this->assertFalse($adaptation['missedKeyWorkout']);
        $this->assertSame(1, $adaptation['skippedSessions7d']);
        $this->assertSame(['illness'], $adaptation['skipReasons7d']);
    }

    public function test_skips_older_than_7_days_or_non_skipped_statuses_are_not_counted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21T12:00:00Z'));

        $this->createSkip('2026-04-13', 'long', 'no_time'); // 8 days before window end -> outside
        $this->createSkip('2026-04-14', 'easy', 'illness'); // exactly 7 days -> inside (inclusive)
        $this->createSkip('2026-04-18', 'easy', 'Illness'); // duplicate reason, different case
        ManualCheckIn::create([
            'user_id' => 1,
            'checkin_key' => 'date:2026-04-20',
            'planned_session_date' => '2026-04-20',
            'status' => 'done',
            'plan_compliance' => 'planned',
            'planned_type' => 'long',
        ]);

        $adaptation = app(TrainingSignalsService::class)->getSignalsForUser(1)['adaptation'];

        $this->assertFalse($adaptation['missedKeyWorkout']);
        $this->assertSame(2, $adaptation['skippedSessions7d']);
        $this->assertSame(['illness'], $adaptation['skipReasons7d']);
    }

    private function createSkip(string $date, string $plannedType, ?string $reason): ManualCheckIn
    {
        return ManualCheckIn::create([
            'user_id' => 1,
            'checkin_key' => 'date:'.$date,
            'planned_session_date' => $date,
            'status' => 'skipped',
            'plan_compliance' => 'skipped',
            'planned_type' => $plannedType,
            'skip_reason' => $reason,
        ]);
    }
}
