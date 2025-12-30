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
        $this->assertTrue(is_infinite((float) $row->duration_ratio));
        $this->assertSame($actualDurationSec, (int) $row->delta_duration_sec);
    }
}


