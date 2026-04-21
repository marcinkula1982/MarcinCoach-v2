<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\PlanComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanComplianceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a default user for tests
        User::create([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_compliance_returns_major_deviation_when_expected_zero_and_actual_positive(): void
    {
        // given
        $service = app(\App\Services\PlanComplianceService::class);

        $workoutId = 1;
        $expectedDurationSec = 0;
        $actualDurationSec = 1800;

        DB::table('workouts')->insert([
            'id' => $workoutId,
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => json_encode([
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => $actualDurationSec,
                'distanceM' => 5000,
            ]),
            'source' => 'manual',
            'source_activity_id' => 'test-expected-zero',
            'dedupe_key' => 'manual:test-expected-zero',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('plan_snapshots')->insert([
            'user_id' => 1,
            'window_start_iso' => '2025-01-01T00:00:00Z',
            'window_end_iso' => '2025-01-01T23:59:59Z',
            'snapshot_json' => json_encode([
                'items' => [[
                    'startTimeIso' => '2025-01-01T10:00:00Z',
                    'expectedDurationSec' => 0,
                ]]
            ]),
            'created_at' => now(),
        ]);

        // when
        $service->upsertForWorkout($workoutId);

        // then
        $row = DB::table('plan_compliance_v1')->where('workout_id', $workoutId)->first();

        $this->assertNotNull($row);
        $this->assertSame('MAJOR_DEVIATION', $row->status);
        $this->assertSame(1, (int) $row->flag_overshoot_duration);
        $this->assertSame(0, (int) $row->flag_undershoot_duration);
        // SQLite cannot store INF as float — it persists NULL instead.
        // Accept either NULL (SQLite/test env) or actual INF (MySQL/prod).
        $this->assertTrue(
            $row->duration_ratio === null || is_infinite((float) $row->duration_ratio),
            'duration_ratio should be INF or NULL (SQLite INF fallback)'
        );
        $this->assertSame($actualDurationSec, (int) $row->delta_duration_sec);
    }

    public function test_compliance_returns_unknown_when_start_time_is_invalid(): void
    {
        $service = app(\App\Services\PlanComplianceService::class);

        DB::table('workouts')->insert([
            'id' => 2,
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => json_encode([
                'startTimeIso' => 'invalid-date',
                'durationSec' => 1200,
                'distanceM' => 3000,
            ]),
            'source' => 'manual',
            'source_activity_id' => 'test-invalid-start',
            'dedupe_key' => 'manual:test-invalid-start',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service->upsertForWorkout(2);
        $row = DB::table('plan_compliance_v1')->where('workout_id', 2)->first();

        $this->assertNotNull($row);
        $this->assertSame('UNKNOWN', $row->status);
        $this->assertNull($row->expected_duration_sec);
    }
}


