<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackfillTrainingSignalsCommandTest extends TestCase
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

    public function test_backfills_signals_for_single_workout(): void
    {
        // Create a workout without signals
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-12-28T10:00:00Z',
                'durationSec' => 3600,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-key-1',
        ]);

        // Verify no signals exist yet
        $this->assertDatabaseMissing('training_signals_v1', [
            'workout_id' => $workout->id,
        ]);

        // Run the command
        $this->artisan('signals:backfill', ['--workoutId' => $workout->id])
            ->expectsOutput("Processing workout ID: {$workout->id}")
            ->expectsOutput("✓ Generated signals for workout ID: {$workout->id}")
            ->assertSuccessful();

        // Verify signals were created
        $this->assertDatabaseHas('training_signals_v1', [
            'workout_id' => $workout->id,
            'duration_sec' => 3600,
            'distance_m' => 10000,
            'duration_bucket' => 'DUR_MEDIUM',
            'flag_very_short' => false,
            'flag_long_run' => false,
        ]);

        // Verify avg_pace_sec_per_km was calculated correctly
        $signals = \Illuminate\Support\Facades\DB::table('training_signals_v1')
            ->where('workout_id', $workout->id)
            ->first();

        $this->assertNotNull($signals);
        $this->assertEquals(360, $signals->avg_pace_sec_per_km); // 3600 / (10000/1000) = 360
    }
}

