<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Workout;
use App\Services\PlanMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M3/M4 beyond current scope — Etap C.
 */
class PlanMemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanMemoryService $service;
    private int $userId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        User::create([
            'id' => 1,
            'name' => 'Memory User',
            'email' => 'memory@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->service = new PlanMemoryService();
    }

    private function makePlan(string $weekStartIso, int $easyMin = 45, int $longMin = 90): array
    {
        return [
            'weekStartIso' => $weekStartIso,
            'sessions' => [
                ['day' => 'mon', 'type' => 'easy', 'durationMin' => $easyMin],
                ['day' => 'tue', 'type' => 'rest', 'durationMin' => 0],
                ['day' => 'wed', 'type' => 'quality', 'durationMin' => 55],
                ['day' => 'thu', 'type' => 'easy', 'durationMin' => $easyMin],
                ['day' => 'fri', 'type' => 'rest', 'durationMin' => 0],
                ['day' => 'sat', 'type' => 'easy', 'durationMin' => $easyMin],
                ['day' => 'sun', 'type' => 'long', 'durationMin' => $longMin],
            ],
        ];
    }

    private function makeBlockContext(): array
    {
        return [
            'block_type' => 'build',
            'block_goal' => 'Rozbudowa bazy',
            'week_role' => 'build',
            'load_direction' => 'increase',
            'key_capability_focus' => 'aerobic_base',
        ];
    }

    public function test_upsert_week_from_plan_inserts_new_week_with_block_fields(): void
    {
        $plan = $this->makePlan('2026-04-20T00:00:00Z');
        $this->service->upsertWeekFromPlan($this->userId, $plan, $this->makeBlockContext());

        $rows = DB::table('training_weeks')->where('user_id', $this->userId)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('2026-04-20', (string) $row->week_start_date);
        $this->assertSame('2026-04-26', (string) $row->week_end_date);
        $this->assertSame('build', $row->block_type);
        $this->assertSame('build', $row->week_role);
        $this->assertSame('increase', $row->load_direction);
        $this->assertSame('aerobic_base', $row->key_capability_focus);
        $this->assertSame(280, (int) $row->planned_total_min); // 45+0+55+45+0+45+90 = 280
        $this->assertSame(1, (int) $row->planned_quality_count);
    }

    public function test_upsert_week_from_plan_is_idempotent_on_same_week(): void
    {
        $plan = $this->makePlan('2026-04-20T00:00:00Z');
        $this->service->upsertWeekFromPlan($this->userId, $plan, $this->makeBlockContext());
        $this->service->upsertWeekFromPlan($this->userId, $plan, $this->makeBlockContext());

        $count = DB::table('training_weeks')->where('user_id', $this->userId)->count();
        $this->assertSame(1, $count);
    }

    public function test_update_week_actuals_computes_totals_from_workouts_in_range(): void
    {
        $plan = $this->makePlan('2026-04-20T00:00:00Z', easyMin: 40, longMin: 80); // planned 40*3+55+80 = 255 -> actually 40+0+55+40+0+40+80=255
        $this->service->upsertWeekFromPlan($this->userId, $plan, $this->makeBlockContext());

        // W tygodniu 2026-04-20..26 są 2 workouty: 30 min easy + 60 min z Z3
        Workout::create([
            'user_id' => $this->userId,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-21T10:00:00Z',
                'trimmed' => ['durationSec' => 1800],
            ],
            'source' => 'manual',
            'dedupe_key' => 'pm-test-1',
        ]);
        Workout::create([
            'user_id' => $this->userId,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-23T10:00:00Z',
                'trimmed' => ['durationSec' => 3600],
                'buckets' => [
                    'z1Sec' => 300, 'z2Sec' => 600, 'z3Sec' => 1500, 'z4Sec' => 1200, 'z5Sec' => 0, 'totalSec' => 3600,
                ],
            ],
            'source' => 'manual',
            'dedupe_key' => 'pm-test-2',
        ]);
        // Workout spoza tygodnia — nie powinien być liczony
        Workout::create([
            'user_id' => $this->userId,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-30T10:00:00Z',
                'trimmed' => ['durationSec' => 7200],
            ],
            'source' => 'manual',
            'dedupe_key' => 'pm-test-3',
        ]);

        $this->service->updateWeekActuals($this->userId, '2026-04-20');

        $row = DB::table('training_weeks')
            ->where('user_id', $this->userId)
            ->where('week_start_date', '2026-04-20')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(90, (int) $row->actual_total_min); // 30 + 60
        $this->assertSame(1, (int) $row->actual_quality_count);
    }

    public function test_update_week_actuals_sets_goal_met_based_on_80pct_threshold(): void
    {
        // planned 200 min: easy*4=160, long=40 -> easy 40*4=160 + long=40 => 200
        $plan = [
            'weekStartIso' => '2026-04-20T00:00:00Z',
            'sessions' => [
                ['day' => 'mon', 'type' => 'easy', 'durationMin' => 40],
                ['day' => 'tue', 'type' => 'easy', 'durationMin' => 40],
                ['day' => 'wed', 'type' => 'easy', 'durationMin' => 40],
                ['day' => 'thu', 'type' => 'easy', 'durationMin' => 40],
                ['day' => 'sun', 'type' => 'long', 'durationMin' => 40],
            ],
        ];
        $this->service->upsertWeekFromPlan($this->userId, $plan, $this->makeBlockContext());

        // 170 minut actual (= 85% z 200) → goal_met=true
        Workout::create([
            'user_id' => $this->userId, 'action' => 'save', 'kind' => 'training',
            'summary' => ['startTimeIso' => '2026-04-22T10:00:00Z', 'trimmed' => ['durationSec' => 170 * 60]],
            'source' => 'manual', 'dedupe_key' => 'pm-goal-ok',
        ]);
        $this->service->updateWeekActuals($this->userId, '2026-04-20');
        $row = DB::table('training_weeks')->where('user_id', $this->userId)->first();
        $this->assertSame(170, (int) $row->actual_total_min);
        $this->assertSame(1, (int) $row->goal_met);

        // Teraz drugi tydzień z actual << planned → goal_met=false
        $plan2 = [
            'weekStartIso' => '2026-04-27T00:00:00Z',
            'sessions' => [
                ['day' => 'mon', 'type' => 'easy', 'durationMin' => 50],
                ['day' => 'tue', 'type' => 'easy', 'durationMin' => 50],
                ['day' => 'wed', 'type' => 'easy', 'durationMin' => 50],
                ['day' => 'sun', 'type' => 'long', 'durationMin' => 50],
            ],
        ];
        $this->service->upsertWeekFromPlan($this->userId, $plan2, $this->makeBlockContext());
        Workout::create([
            'user_id' => $this->userId, 'action' => 'save', 'kind' => 'training',
            'summary' => ['startTimeIso' => '2026-04-28T10:00:00Z', 'trimmed' => ['durationSec' => 30 * 60]],
            'source' => 'manual', 'dedupe_key' => 'pm-goal-fail',
        ]);
        $this->service->updateWeekActuals($this->userId, '2026-04-27');
        $row2 = DB::table('training_weeks')
            ->where('user_id', $this->userId)->where('week_start_date', '2026-04-27')->first();
        $this->assertSame(0, (int) $row2->goal_met);
    }

    public function test_get_recent_weeks_returns_descending_by_week_start_date(): void
    {
        $ctx = $this->makeBlockContext();
        $this->service->upsertWeekFromPlan($this->userId, $this->makePlan('2026-03-30T00:00:00Z'), $ctx);
        $this->service->upsertWeekFromPlan($this->userId, $this->makePlan('2026-04-13T00:00:00Z'), $ctx);
        $this->service->upsertWeekFromPlan($this->userId, $this->makePlan('2026-04-06T00:00:00Z'), $ctx);
        $this->service->upsertWeekFromPlan($this->userId, $this->makePlan('2026-04-20T00:00:00Z'), $ctx);

        $weeks = $this->service->getRecentWeeks($this->userId, 3);
        $this->assertCount(3, $weeks);
        $this->assertSame('2026-04-20', $weeks[0]['week_start_date']);
        $this->assertSame('2026-04-13', $weeks[1]['week_start_date']);
        $this->assertSame('2026-04-06', $weeks[2]['week_start_date']);
    }

    public function test_get_week_goal_met_returns_null_when_row_missing(): void
    {
        $this->assertNull($this->service->getWeekGoalMet($this->userId, '2000-01-03'));
    }

    public function test_update_week_actuals_is_noop_when_week_row_missing(): void
    {
        $this->service->updateWeekActuals($this->userId, '2026-04-20');
        $count = DB::table('training_weeks')->where('user_id', $this->userId)->count();
        $this->assertSame(0, $count);
    }
}
