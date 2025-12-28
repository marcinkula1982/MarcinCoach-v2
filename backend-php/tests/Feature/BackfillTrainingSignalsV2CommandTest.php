<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillTrainingSignalsV2CommandTest extends TestCase
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

    public function test_backfills_signals_v2_for_single_workout(): void
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

        // Create workout with TCX
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-12-28T10:00:00Z',
                'durationSec' => 60,
                'distanceM' => 200,
            ],
            'source' => 'tcx',
            'dedupe_key' => 'test-signals-v2-backfill',
        ]);

        // Add raw TCX XML
        $tcxXml = '<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">
  <Activities>
    <Activity Sport="Running">
      <Id>2025-12-28T10:00:00Z</Id>
      <Lap StartTime="2025-12-28T10:00:00Z">
        <TotalTimeSeconds>60</TotalTimeSeconds>
        <DistanceMeters>200</DistanceMeters>
        <Track>
          <Trackpoint>
            <Time>2025-12-28T10:00:00Z</Time>
            <HeartRateBpm><Value>120</Value></HeartRateBpm>
            <DistanceMeters>0</DistanceMeters>
          </Trackpoint>
          <Trackpoint>
            <Time>2025-12-28T10:01:00Z</Time>
            <HeartRateBpm><Value>140</Value></HeartRateBpm>
            <DistanceMeters>200</DistanceMeters>
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

        // Verify no signals v2 exist yet
        $this->assertDatabaseMissing('training_signals_v2', [
            'workout_id' => $workout->id,
        ]);

        // Run the command
        $this->artisan('signals:backfill-v2', ['--workoutId' => $workout->id])
            ->expectsOutput("Processing workout ID: {$workout->id}")
            ->expectsOutput("✓ Generated signals v2 for workout ID: {$workout->id}")
            ->assertSuccessful();

        // Verify signals v2 were created
        $this->assertDatabaseHas('training_signals_v2', [
            'workout_id' => $workout->id,
            'hr_available' => true,
            'hr_avg_bpm' => 130, // (120 + 140) / 2 = 130
            'hr_max_bpm' => 140,
        ]);

        $signals = DB::table('training_signals_v2')->where('workout_id', $workout->id)->first();
        $this->assertNotNull($signals);
        // Trackpoint at 10:00:00 has HR=120 (Z2), next at 10:01:00, so 60 seconds in Z2
        $this->assertEquals(60, $signals->hr_z2_sec);
    }
}

