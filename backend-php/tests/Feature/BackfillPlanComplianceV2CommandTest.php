<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillPlanComplianceV2CommandTest extends TestCase
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

    public function test_backfills_compliance_v2_for_single_workout(): void
    {
        // Create user profile with HR zones
        DB::table('user_profiles')->insert([
            'user_id' => 1,
            'hr_z1_min' => 50,
            'hr_z1_max' => 100,
            'hr_z2_min' => 100,
            'hr_z2_max' => 130,
            'hr_z3_min' => 130,
            'hr_z3_max' => 150,
            'hr_z4_min' => 150,
            'hr_z4_max' => 170,
            'hr_z5_min' => 170,
            'hr_z5_max' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create plan_snapshot with expectedHrZone
        $plannedWorkout = [
            'startTimeIso' => '2025-12-28T10:00:00Z',
            'expectedDurationSec' => 3600,
            'expectedDistanceM' => 10000,
            'expectedHrZoneMin' => 1,
            'expectedHrZoneMax' => 2,
        ];
        
        $snapshotJson = json_encode(['items' => [$plannedWorkout]]);
        
        DB::table('plan_snapshots')->insert([
            'user_id' => 1,
            'snapshot_json' => $snapshotJson,
            'window_start_iso' => '2025-12-28T00:00:00Z',
            'window_end_iso' => '2025-12-28T23:59:59Z',
            'created_at' => now(),
        ]);

        // Create workout with TCX
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-12-28T10:00:00Z',
                'durationSec' => 3600,
                'distanceM' => 10000,
            ],
            'source' => 'tcx',
            'dedupe_key' => 'test-compliance-v2-backfill',
        ]);

        // Add raw TCX XML
        $tcxXml = '<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">
  <Activities>
    <Activity Sport="Running">
      <Id>2025-12-28T10:00:00Z</Id>
      <Lap StartTime="2025-12-28T10:00:00Z">
        <TotalTimeSeconds>3600</TotalTimeSeconds>
        <DistanceMeters>10000</DistanceMeters>
        <Track>
          <Trackpoint>
            <Time>2025-12-28T10:00:00Z</Time>
            <HeartRateBpm><Value>120</Value></HeartRateBpm>
            <DistanceMeters>0</DistanceMeters>
          </Trackpoint>
          <Trackpoint>
            <Time>2025-12-28T11:00:00Z</Time>
            <HeartRateBpm><Value>125</Value></HeartRateBpm>
            <DistanceMeters>10000</DistanceMeters>
          </Trackpoint>
        </Track>
      </Lap>
    </Activity>
  </Activities>
</TrainingCenterDatabase>';

        DB::table('workout_raw_tcx')->insert([
            'workout_id' => $workout->id,
            'xml' => $tcxXml,
            'created_at' => now(),
        ]);

        // Create signals_v2 manually (simulating import)
        DB::table('training_signals_v2')->insert([
            'workout_id' => $workout->id,
            'hr_available' => true,
            'hr_avg_bpm' => 122,
            'hr_max_bpm' => 125,
            'hr_z1_sec' => 0,
            'hr_z2_sec' => 3600,
            'hr_z3_sec' => 0,
            'hr_z4_sec' => 0,
            'hr_z5_sec' => 0,
            'generated_at' => now(),
        ]);

        // Verify no compliance v2 exists yet
        $this->assertDatabaseMissing('plan_compliance_v2', [
            'workout_id' => $workout->id,
        ]);

        // Run the command
        $this->artisan('compliance:backfill-v2', ['--workoutId' => $workout->id])
            ->expectsOutput("Processing workout ID: {$workout->id}")
            ->expectsOutput("✓ Generated compliance v2 for workout ID: {$workout->id}")
            ->assertSuccessful();

        // Verify compliance v2 was created
        $this->assertDatabaseHas('plan_compliance_v2', [
            'workout_id' => $workout->id,
            'expected_hr_zone_min' => 1,
            'expected_hr_zone_max' => 2,
            'status' => 'OK', // All time in Z2, ratio=0 <= 0.10 -> OK
        ]);

        $compliance = DB::table('plan_compliance_v2')->where('workout_id', $workout->id)->first();
        $this->assertNotNull($compliance);
        $this->assertEquals(0, $compliance->high_intensity_ratio);
    }
}

