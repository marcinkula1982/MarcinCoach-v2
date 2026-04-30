<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use App\Services\PlanSnapshotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Integration tests for automatic plan snapshot persistence.
 *
 * Covered:
 *  1. WeeklyPlanController saves a plan_snapshots row after generating a plan.
 *  2. RollingPlanController saves plan_snapshots rows (two weeks) after generating a plan.
 *  3. Saved snapshot contains sessions[] with dateIso, type, durationMin, intensityHint.
 *  4. Weekly-plan and rolling-plan endpoint contracts remain unchanged.
 *  5. No snapshot is saved when PlanSnapshotService::saveFromPlan has no mappable sessions
 *     (simulated via mock that counts calls).
 *  6. If PlanSnapshotService throws, the endpoint still returns HTTP 200.
 */
class PlanSnapshotIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);

        User::create([
            'id' => 1,
            'name' => 'Snapshot Test User',
            'email' => 'snapshot@example.com',
            'password' => bcrypt('password'),
        ]);

        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 3000,
                'distanceM' => 7000,
                'sport' => 'run',
                'intensity' => 28,
            ],
            'source' => 'manual',
            'dedupe_key' => 'snapshot-test-baseline',
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. WeeklyPlanController saves snapshot
    // -------------------------------------------------------------------------

    public function test_weekly_plan_saves_plan_snapshot(): void
    {
        $this->getJson('/api/weekly-plan?days=28')->assertOk();

        $count = DB::table('plan_snapshots')->where('user_id', 1)->count();
        $this->assertGreaterThanOrEqual(1, $count, 'Expected at least one plan_snapshot row after GET /api/weekly-plan');
    }

    public function test_weekly_plan_snapshot_has_source_weekly(): void
    {
        $this->getJson('/api/weekly-plan?days=28')->assertOk();

        $row = DB::table('plan_snapshots')->where('user_id', 1)->latest('id')->first();
        $this->assertNotNull($row);

        $json = json_decode($row->snapshot_json, true);
        $this->assertSame('plan-snapshot-v2', $json['version'] ?? null);
        $this->assertSame('weekly', $json['source'] ?? null);
    }

    // -------------------------------------------------------------------------
    // 2. RollingPlanController saves snapshots (two weeks)
    // -------------------------------------------------------------------------

    public function test_rolling_plan_saves_plan_snapshots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21T12:00:00Z'));

        try {
            $this->getJson('/api/rolling-plan?days=14')->assertOk();

            $count = DB::table('plan_snapshots')->where('user_id', 1)->count();
            $this->assertGreaterThanOrEqual(2, $count, 'Expected at least two plan_snapshot rows after GET /api/rolling-plan (one per week)');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rolling_plan_snapshot_has_source_rolling(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21T12:00:00Z'));

        try {
            $this->getJson('/api/rolling-plan?days=14')->assertOk();

            $rows = DB::table('plan_snapshots')->where('user_id', 1)->get();
            $this->assertNotEmpty($rows);

            foreach ($rows as $row) {
                $json = json_decode($row->snapshot_json, true);
                $this->assertSame('plan-snapshot-v2', $json['version'] ?? null, 'version must be plan-snapshot-v2');
                $this->assertSame('rolling', $json['source'] ?? null, 'source must be rolling');
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    // -------------------------------------------------------------------------
    // 3. Snapshot sessions contain required fields
    // -------------------------------------------------------------------------

    public function test_weekly_snapshot_sessions_have_required_fields(): void
    {
        $this->getJson('/api/weekly-plan?days=28')->assertOk();

        $row = DB::table('plan_snapshots')->where('user_id', 1)->latest('id')->first();
        $this->assertNotNull($row);

        $json = json_decode($row->snapshot_json, true);
        $this->assertIsArray($json['sessions'] ?? null, 'snapshot must have sessions array');
        $this->assertNotEmpty($json['sessions'], 'sessions must not be empty');

        foreach ($json['sessions'] as $index => $session) {
            $this->assertArrayHasKey('dateIso', $session, "session[$index] must have dateIso");
            $this->assertArrayHasKey('type', $session, "session[$index] must have type");
            $this->assertArrayHasKey('durationMin', $session, "session[$index] must have durationMin");
            $this->assertArrayHasKey('intensityHint', $session, "session[$index] must have intensityHint");
            // dateIso must be a valid YYYY-MM-DD string
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $session['dateIso'], "session[$index].dateIso must match YYYY-MM-DD");
        }
    }

    public function test_weekly_snapshot_window_fields_set(): void
    {
        $this->getJson('/api/weekly-plan?days=28')->assertOk();

        $row = DB::table('plan_snapshots')->where('user_id', 1)->latest('id')->first();
        $this->assertNotNull($row);

        $this->assertNotEmpty($row->window_start_iso, 'window_start_iso column must not be empty');
        $this->assertNotEmpty($row->window_end_iso, 'window_end_iso column must not be empty');
    }

    // -------------------------------------------------------------------------
    // 4. Endpoint contracts unchanged
    // -------------------------------------------------------------------------

    public function test_weekly_plan_contract_still_intact_after_snapshot_integration(): void
    {
        $response = $this->getJson('/api/weekly-plan?days=28');
        $response->assertOk();
        $response->assertJsonStructure([
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
    }

    public function test_rolling_plan_contract_still_intact_after_snapshot_integration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21T12:00:00Z'));

        try {
            $response = $this->getJson('/api/rolling-plan?days=14');
            $response->assertOk();
            $response->assertJsonStructure([
                'generatedAtIso',
                'windowDays',
                'horizonEndIso',
                'sessions',
                'weeks',
                'summary',
                'decisionTrace',
                'nextSession',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    // -------------------------------------------------------------------------
    // 5. No snapshot saved when plan has no mappable sessions
    // -------------------------------------------------------------------------

    public function test_no_snapshot_saved_if_plan_has_no_sessions(): void
    {
        // Build a snapshot array with an empty sessions list — saveFromPlan must return silently.
        $service = new PlanSnapshotService();

        $emptyPlan = [
            'weekStartIso' => '2026-04-21T00:00:00.000Z',
            'weekEndIso'   => '2026-04-27T23:59:59.000Z',
            'sessions'     => [],
        ];

        $before = DB::table('plan_snapshots')->count();
        $service->saveFromPlan(1, $emptyPlan, 'weekly');
        $after = DB::table('plan_snapshots')->count();

        $this->assertSame($before, $after, 'saveFromPlan must not write a row when sessions is empty');
    }

    public function test_no_snapshot_saved_if_plan_sessions_have_no_day_or_dateIso(): void
    {
        $service = new PlanSnapshotService();

        $badPlan = [
            'weekStartIso' => '2026-04-21T00:00:00.000Z',
            'weekEndIso'   => '2026-04-27T23:59:59.000Z',
            'sessions'     => [
                ['type' => 'easy', 'durationMin' => 40],     // no day, no dateIso
                ['day' => 'INVALID', 'type' => 'easy', 'durationMin' => 40], // unrecognised day
            ],
        ];

        $before = DB::table('plan_snapshots')->count();
        $service->saveFromPlan(1, $badPlan, 'weekly');
        $after = DB::table('plan_snapshots')->count();

        $this->assertSame($before, $after, 'saveFromPlan must not write a row when no session can be mapped to dateIso');
    }

    public function test_no_snapshot_saved_if_window_missing(): void
    {
        $service = new PlanSnapshotService();

        $noWindowPlan = [
            'sessions' => [['day' => 'mon', 'type' => 'easy', 'durationMin' => 40]],
        ];

        $before = DB::table('plan_snapshots')->count();
        $service->saveFromPlan(1, $noWindowPlan, 'weekly');
        $after = DB::table('plan_snapshots')->count();

        $this->assertSame($before, $after, 'saveFromPlan must not write a row when weekStartIso/weekEndIso is missing');
    }

    // -------------------------------------------------------------------------
    // 6. Endpoint returns 200 even when PlanSnapshotService throws
    // -------------------------------------------------------------------------

    public function test_weekly_plan_returns_200_when_snapshot_service_throws(): void
    {
        $mock = $this->createMock(PlanSnapshotService::class);
        $mock->method('saveFromPlan')->willThrowException(new \RuntimeException('DB unavailable'));
        $mock->method('saveForUser')->willThrowException(new \RuntimeException('DB unavailable'));

        $this->app->instance(PlanSnapshotService::class, $mock);

        $response = $this->getJson('/api/weekly-plan?days=28');
        $response->assertOk();
    }

    public function test_rolling_plan_returns_200_when_snapshot_service_throws(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21T12:00:00Z'));

        try {
            $mock = $this->createMock(PlanSnapshotService::class);
            $mock->method('saveFromPlan')->willThrowException(new \RuntimeException('DB unavailable'));
            $mock->method('saveForUser')->willThrowException(new \RuntimeException('DB unavailable'));

            $this->app->instance(PlanSnapshotService::class, $mock);

            $response = $this->getJson('/api/rolling-plan?days=14');
            $response->assertOk();
        } finally {
            Carbon::setTestNow();
        }
    }
}
