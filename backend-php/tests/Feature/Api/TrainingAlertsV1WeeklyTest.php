<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\PlanMemoryService;
use App\Services\TrainingAlertsV1Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M3/M4 beyond current scope — Etap F.
 */
class TrainingAlertsV1WeeklyTest extends TestCase
{
    use RefreshDatabase;

    private int $userId = 1;
    private TrainingAlertsV1Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        User::create([
            'id' => $this->userId,
            'name' => 'Weekly Alerts User',
            'email' => 'weekly-alerts@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->service = app(TrainingAlertsV1Service::class);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function insertWeek(string $weekStartDate, array $overrides = []): int
    {
        $end = \Carbon\CarbonImmutable::parse($weekStartDate)->addDays(6)->toDateString();
        $row = array_replace([
            'user_id' => $this->userId,
            'week_start_date' => $weekStartDate,
            'week_end_date' => $end,
            'block_type' => 'base',
            'week_role' => 'build',
            'block_goal' => 'Base',
            'key_capability_focus' => 'aerobic_base',
            'load_direction' => 'increase',
            'planned_total_min' => 300,
            'actual_total_min' => 280,
            'planned_quality_count' => 1,
            'actual_quality_count' => 1,
            'goal_met' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        DB::table('training_weeks')->insert($row);
        return (int) DB::table('training_weeks')
            ->where('user_id', $this->userId)
            ->where('week_start_date', $weekStartDate)
            ->value('id');
    }

    private function insertProfile(bool $hasCurrentPain = false): void
    {
        DB::table('user_profiles')->updateOrInsert(
            ['user_id' => $this->userId],
            [
                'max_session_min' => 120,
                'has_current_pain' => $hasCurrentPain ? 1 : 0,
                'has_hr_sensor' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function test_under_recovery_trend_alert_when_decrease_weeks_with_missed_quality(): void
    {
        $this->insertProfile();
        // 3 najnowsze tygodnie, 2 z nich decrease + actualQ < plannedQ.
        $currentWeekId = $this->insertWeek('2026-04-20', [
            'load_direction' => 'decrease',
            'planned_quality_count' => 2,
            'actual_quality_count' => 0,
        ]);
        $this->insertWeek('2026-04-13', [
            'load_direction' => 'decrease',
            'planned_quality_count' => 2,
            'actual_quality_count' => 1,
        ]);
        $this->insertWeek('2026-04-06', [
            'load_direction' => 'increase',
            'planned_quality_count' => 1,
            'actual_quality_count' => 1,
        ]);

        $this->service->upsertWeeklyAlerts($this->userId, '2026-04-20');

        $alert = DB::table('training_alerts_v1')
            ->where('week_id', $currentWeekId)
            ->whereNull('workout_id')
            ->where('code', 'UNDER_RECOVERY_TREND')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('trend', $alert->family);
        $this->assertSame('medium', $alert->confidence);
        $this->assertSame('under_recovery_trend', $alert->explanation_code);
    }

    public function test_execution_drift_alert_when_three_weeks_under_75pct(): void
    {
        $this->insertProfile();
        $weekId = $this->insertWeek('2026-04-20', [
            'planned_total_min' => 400,
            'actual_total_min' => 200, // 0.50
        ]);
        $this->insertWeek('2026-04-13', [
            'planned_total_min' => 400,
            'actual_total_min' => 240, // 0.60
        ]);
        $this->insertWeek('2026-04-06', [
            'planned_total_min' => 400,
            'actual_total_min' => 280, // 0.70
        ]);

        $this->service->upsertWeeklyAlerts($this->userId, '2026-04-20');

        $alert = DB::table('training_alerts_v1')
            ->where('week_id', $weekId)
            ->where('code', 'EXECUTION_DRIFT')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('compliance', $alert->family);
        $this->assertSame('high', $alert->confidence);
    }

    public function test_excessive_density_trend_alert_when_two_weeks_of_high_density(): void
    {
        $this->insertProfile();
        $weekId = $this->insertWeek('2026-04-20', [
            'actual_quality_count' => 4,
        ]);
        $this->insertWeek('2026-04-13', [
            'actual_quality_count' => 3,
        ]);

        $this->service->upsertWeeklyAlerts($this->userId, '2026-04-20');

        $alert = DB::table('training_alerts_v1')
            ->where('week_id', $weekId)
            ->where('code', 'EXCESSIVE_DENSITY_TREND')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('safety', $alert->family);
    }

    public function test_pain_with_load_conflict_alert_when_profile_has_pain_and_quality_planned(): void
    {
        $this->insertProfile(hasCurrentPain: true);
        $weekId = $this->insertWeek('2026-04-20', [
            'planned_quality_count' => 2,
        ]);

        $this->service->upsertWeeklyAlerts($this->userId, '2026-04-20');

        $alert = DB::table('training_alerts_v1')
            ->where('week_id', $weekId)
            ->where('code', 'PAIN_WITH_LOAD_CONFLICT')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('safety', $alert->family);
        $this->assertSame('CRITICAL', $alert->severity);
        $this->assertSame('high', $alert->confidence);
    }

    public function test_block_goal_not_met_alert_when_recovery_week_after_missed_block(): void
    {
        $this->insertProfile();
        // Bieżący tydzień: recovery w bloku build (goal_met=0, bez jakości)
        $weekId = $this->insertWeek('2026-04-20', [
            'block_type' => 'build',
            'week_role' => 'recovery',
            'load_direction' => 'decrease',
            'planned_quality_count' => 0,
            'actual_quality_count' => 0,
            'goal_met' => 0,
        ]);
        // 2 wcześniejsze tygodnie tego samego bloku — goal_met=false
        $this->insertWeek('2026-04-13', [
            'block_type' => 'build',
            'week_role' => 'build',
            'goal_met' => 0,
            'planned_quality_count' => 1,
            'actual_quality_count' => 0,
        ]);
        $this->insertWeek('2026-04-06', [
            'block_type' => 'build',
            'week_role' => 'build',
            'goal_met' => 0,
            'planned_quality_count' => 1,
            'actual_quality_count' => 0,
        ]);

        $this->service->upsertWeeklyAlerts($this->userId, '2026-04-20');

        $alert = DB::table('training_alerts_v1')
            ->where('week_id', $weekId)
            ->where('code', 'BLOCK_GOAL_NOT_MET')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('compliance', $alert->family);
    }

    public function test_upsert_weekly_alerts_is_noop_when_week_row_missing(): void
    {
        $this->service->upsertWeeklyAlerts($this->userId, '2000-01-03');
        $count = DB::table('training_alerts_v1')->count();
        $this->assertSame(0, $count);
    }
}
