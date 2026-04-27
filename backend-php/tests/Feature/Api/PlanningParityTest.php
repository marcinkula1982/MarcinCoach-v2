<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanningParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        User::create([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_training_context_and_weekly_plan_endpoints(): void
    {
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 2000,
                'distanceM' => 5500,
                'intensity' => 25,
            ],
            'source' => 'manual',
            'dedupe_key' => 'planning-parity-test',
        ]);

        $ctx = $this->getJson('/api/training-context?days=28');
        $ctx->assertOk();
        $ctx->assertJsonStructure(['generatedAtIso', 'windowDays', 'signals', 'profile']);

        $plan = $this->getJson('/api/weekly-plan?days=28');
        $plan->assertOk();
        $plan->assertJsonStructure([
            'generatedAtIso',
            'weekStartIso',
            'weekEndIso',
            'windowDays',
            'inputsHash',
            'sessions',
            'summary',
            'rationale',
            'appliedAdjustmentsCodes',
        ]);

        $sessions = $plan->json('sessions');
        $qualityCount = count(array_filter($sessions, fn ($s) => ($s['type'] ?? null) === 'quality'));
        $this->assertSame($qualityCount, (int) $plan->json('summary.qualitySessions'));
        foreach ($sessions as $session) {
            $this->assertArrayNotHasKey('techniqueFocus', $session);
            $this->assertArrayNotHasKey('surfaceHint', $session);
        }
        $this->assertContains('surface_constraint', $plan->json('appliedAdjustmentsCodes'));
    }

    public function test_training_context_uses_user_training_analysis_load_contract(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27T10:00:00Z'));

        try {
            Workout::create([
                'user_id' => 1,
                'action' => 'save',
                'kind' => 'training',
                'summary' => [
                    'startTimeIso' => '2026-04-25T10:00:00Z',
                    'durationSec' => 3600,
                    'distanceM' => 10000,
                    'sport' => 'run',
                    'intensity' => 999,
                ],
                'source' => 'manual',
                'dedupe_key' => 'planning-analysis-load-contract',
            ]);

            $ctx = $this->getJson('/api/training-context?days=28');
            $ctx->assertOk();

            $this->assertSame(60.0, (float) $ctx->json('signals.weeklyLoad'));
            $this->assertSame(60.0, (float) $ctx->json('signals.rolling4wLoad'));
            $this->assertSame(1, (int) $ctx->json('signals.totalWorkouts'));
            $this->assertSame(10.0, (float) $ctx->json('signals.longRun.distanceKm'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_weekly_plan_uses_total_workouts_from_user_training_analysis_contract(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21T12:00:00Z'));

        try {
            Workout::create([
                'user_id' => 1,
                'action' => 'save',
                'kind' => 'training',
                'summary' => ['startTimeIso' => '2026-03-30T10:00:00Z', 'durationSec' => 1800, 'distanceM' => 5000, 'intensity' => 20],
                'source' => 'manual',
                'dedupe_key' => 'planning-contract-freeze-1',
            ]);
            Workout::create([
                'user_id' => 1,
                'action' => 'save',
                'kind' => 'training',
                'summary' => ['startTimeIso' => '2026-04-06T10:00:00Z', 'durationSec' => 1800, 'distanceM' => 5000, 'intensity' => 20],
                'source' => 'manual',
                'dedupe_key' => 'planning-contract-freeze-2',
            ]);
            Workout::create([
                'user_id' => 1,
                'action' => 'save',
                'kind' => 'training',
                'summary' => ['startTimeIso' => '2026-04-13T10:00:00Z', 'durationSec' => 1800, 'distanceM' => 5000, 'intensity' => 20],
                'source' => 'manual',
                'dedupe_key' => 'planning-contract-freeze-3',
            ]);
            Workout::create([
                'user_id' => 1,
                'action' => 'save',
                'kind' => 'training',
                'summary' => ['startTimeIso' => '2026-04-20T10:00:00Z', 'durationSec' => 1800, 'distanceM' => 5000, 'intensity' => 20],
                'source' => 'manual',
                'dedupe_key' => 'planning-contract-freeze-4',
            ]);

            $plan = $this->getJson('/api/weekly-plan?days=28');
            $plan->assertOk();

            $this->assertGreaterThanOrEqual(1, (int) $plan->json('summary.qualitySessions'));
            $this->assertContains('surface_constraint', $plan->json('appliedAdjustmentsCodes'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_post_race_week_blocks_quality_in_weekly_plan(): void
    {
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => ['startTimeIso' => '2026-04-18T10:00:00Z', 'durationSec' => 1800, 'distanceM' => 5000, 'intensity' => 20],
            'source' => 'manual',
            'dedupe_key' => 'planning-race-1',
        ]);
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => ['startTimeIso' => '2026-04-19T10:00:00Z', 'durationSec' => 2000, 'distanceM' => 5500, 'intensity' => 22],
            'source' => 'manual',
            'dedupe_key' => 'planning-race-2',
        ]);
        // Latest workout marked as race => post-race easy week safety
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'race',
            'summary' => ['startTimeIso' => '2026-04-20T10:00:00Z', 'durationSec' => 3600, 'distanceM' => 10000, 'intensity' => 45],
            'race_meta' => ['eventName' => '10K'],
            'source' => 'manual',
            'dedupe_key' => 'planning-race-3',
        ]);

        $plan = $this->getJson('/api/weekly-plan?days=28');
        $plan->assertOk();

        $sessions = $plan->json('sessions');
        $qualityCount = count(array_filter($sessions, fn ($s) => ($s['type'] ?? null) === 'quality'));
        $this->assertSame(0, $qualityCount);
        $this->assertContains('recovery_focus', $plan->json('appliedAdjustmentsCodes'));
        $this->assertContains('control_start_followup', $plan->json('appliedAdjustmentsCodes'));
        $this->assertContains('surface_constraint', $plan->json('appliedAdjustmentsCodes'));
    }

    // --- M1 beyond minimum regressions ---

    public function test_weekly_plan_contract_unchanged_when_no_max_session_min(): void
    {
        // Explicitly set a profile with NO maxSessionMin — cap must not apply
        $this->putJson('/api/me/profile', [
            'availability' => ['runningDays' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']],
            // no maxSessionMin
        ]);

        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => ['startTimeIso' => '2026-04-20T10:00:00Z', 'durationSec' => 3600, 'distanceM' => 10000, 'intensity' => 35],
            'source' => 'manual',
            'dedupe_key' => 'm1-beyond-no-cap',
        ]);

        $plan = $this->getJson('/api/weekly-plan?days=28');
        $plan->assertOk();

        // Contract shape must be intact
        $plan->assertJsonStructure([
            'generatedAtIso',
            'weekStartIso',
            'weekEndIso',
            'windowDays',
            'inputsHash',
            'sessions',
            'summary',
            'rationale',
            'appliedAdjustmentsCodes',
        ]);

        // Without cap, long run must be ≥ 75 min
        $sessions = $plan->json('sessions');
        $longSessions = array_values(array_filter($sessions, fn ($s) => ($s['type'] ?? null) === 'long'));
        $this->assertNotEmpty($longSessions);
        $this->assertGreaterThanOrEqual(75, (int) $longSessions[0]['durationMin']);
    }

    public function test_weekly_plan_sessions_capped_when_max_session_min_set(): void
    {
        $this->putJson('/api/me/profile', [
            'availability' => ['runningDays' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'maxSessionMin' => 50],
            'health' => ['injuryHistory' => [], 'currentPain' => false],
            'equipment' => ['watch' => true, 'hrSensor' => false],
        ]);

        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => ['startTimeIso' => '2026-04-20T10:00:00Z', 'durationSec' => 3600, 'distanceM' => 10000, 'intensity' => 35],
            'source' => 'manual',
            'dedupe_key' => 'm1-beyond-cap-50',
        ]);

        $plan = $this->getJson('/api/weekly-plan?days=28');
        $plan->assertOk();

        foreach ($plan->json('sessions') as $session) {
            $this->assertLessThanOrEqual(50, (int) ($session['durationMin'] ?? 0), 'Session exceeds maxSessionMin cap');
        }
    }

    public function test_weekly_plan_persists_training_weeks_row_with_block_context(): void
    {
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 2000,
                'distanceM' => 5500,
                'intensity' => 25,
            ],
            'source' => 'manual',
            'dedupe_key' => 'planning-parity-training-weeks',
        ]);

        $plan = $this->getJson('/api/weekly-plan?days=28');
        $plan->assertOk();

        $rows = DB::table('training_weeks')->where('user_id', 1)->get();
        $this->assertCount(1, $rows, 'Expected exactly one training_weeks row after GET /api/weekly-plan');

        $row = $rows->first();
        $this->assertNotEmpty($row->block_type, 'block_type should be populated from BlockPeriodizationService');
        $this->assertNotEmpty($row->week_role);
        $this->assertGreaterThan(0, (int) $row->planned_total_min);
    }

    public function test_m4_easier_streak_applies_progression_adjustment_code(): void
    {
        $w1 = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => ['startTimeIso' => '2026-04-18T10:00:00Z', 'durationSec' => 1800, 'distanceM' => 5000, 'intensity' => 18],
            'source' => 'manual',
            'dedupe_key' => 'planning-m4-easy-1',
        ]);
        $w2 = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => ['startTimeIso' => '2026-04-19T10:00:00Z', 'durationSec' => 1800, 'distanceM' => 5000, 'intensity' => 18],
            'source' => 'manual',
            'dedupe_key' => 'planning-m4-easy-2',
        ]);
        $w3 = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => ['startTimeIso' => '2026-04-20T10:00:00Z', 'durationSec' => 1800, 'distanceM' => 5000, 'intensity' => 18],
            'source' => 'manual',
            'dedupe_key' => 'planning-m4-easy-3',
        ]);

        foreach ([$w1->id, $w2->id, $w3->id] as $id) {
            DB::table('plan_compliance_v1')->insert([
                'workout_id' => $id,
                'expected_duration_sec' => 3600,
                'actual_duration_sec' => 1800,
                'delta_duration_sec' => -1800,
                'duration_ratio' => 0.5,
                'status' => 'MAJOR_DEVIATION',
                'flag_overshoot_duration' => false,
                'flag_undershoot_duration' => true,
                'generated_at' => now(),
            ]);
        }

        $plan = $this->getJson('/api/weekly-plan?days=28');
        $plan->assertOk();
        $this->assertContains('easier_than_planned_progression', $plan->json('appliedAdjustmentsCodes'));
    }
}
