<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillPlanComplianceCommandTest extends TestCase
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

    public function test_backfills_compliance_for_single_workout(): void
    {
        // Create plan_snapshot with planned workout matching the workout time window
        $plannedWorkout = [
            'startTimeIso' => '2025-12-28T10:00:00Z',
            'expectedDurationSec' => 3600,
            'expectedDistanceM' => 10000,
        ];
        
        $snapshotJson = json_encode(['items' => [$plannedWorkout]]);
        
        DB::table('plan_snapshots')->insert([
            'user_id' => 1,
            'snapshot_json' => $snapshotJson,
            'window_start_iso' => '2025-12-28T00:00:00Z',
            'window_end_iso' => '2025-12-28T23:59:59Z',
            'created_at' => now(),
        ]);

        // Create a workout without compliance
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-12-28T10:00:00Z',
                'durationSec' => 3700,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-compliance-backfill',
        ]);

        // Verify no compliance exists yet
        $this->assertDatabaseMissing('plan_compliance_v1', [
            'workout_id' => $workout->id,
        ]);

        // Run the command
        $this->artisan('compliance:backfill', ['--workoutId' => $workout->id])
            ->expectsOutput("Processing workout ID: {$workout->id}")
            ->expectsOutput("✓ Generated compliance for workout ID: {$workout->id}")
            ->assertSuccessful();

        // Verify compliance was created with correct status
        $this->assertDatabaseHas('plan_compliance_v1', [
            'workout_id' => $workout->id,
            'expected_duration_sec' => 3600,
            'actual_duration_sec' => 3700,
            'delta_duration_sec' => 100,
            'status' => 'OK', // 3700 / 3600 = 1.0277, which is within 0.85-1.15 range
        ]);

        $compliance = DB::table('plan_compliance_v1')->where('workout_id', $workout->id)->first();
        $this->assertNotNull($compliance);
        $this->assertEqualsWithDelta(1.0277, $compliance->duration_ratio, 0.01);
        $this->assertEquals('OK', $compliance->status);
    }
}

