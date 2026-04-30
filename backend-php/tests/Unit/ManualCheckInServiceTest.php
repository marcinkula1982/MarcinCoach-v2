<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Workout;
use App\Services\ManualCheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualCheckInServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'id' => 1,
            'name' => 'Manual Service User',
            'email' => 'manual-service@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_done_check_in_uses_date_key_for_idempotency(): void
    {
        $service = app(ManualCheckInService::class);

        $first = $service->upsert(1, [
            'plannedSessionDate' => '2026-04-29',
            'status' => 'done',
            'plannedDurationMin' => 30,
            'durationMin' => 30,
        ]);
        $second = $service->upsert(1, [
            'plannedSessionDate' => '2026-04-29',
            'status' => 'done',
            'plannedDurationMin' => 30,
            'durationMin' => 35,
        ]);

        $this->assertSame(201, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame($first['body']['checkIn']['id'], $second['body']['checkIn']['id']);
        $this->assertSame($first['body']['checkIn']['workoutId'], $second['body']['checkIn']['workoutId']);
        $this->assertDatabaseCount('manual_check_ins', 1);
        $this->assertDatabaseCount('workouts', 1);
        $this->assertSame(35, (int) DB::table('manual_check_ins')->value('actual_duration_min'));
    }

    public function test_modified_check_in_writes_manual_duration_compliance(): void
    {
        $service = app(ManualCheckInService::class);

        $result = $service->upsert(1, [
            'plannedSessionDate' => '2026-04-29',
            'plannedSessionId' => 'session-a',
            'status' => 'modified',
            'plannedDurationMin' => 50,
            'actualDurationMin' => 70,
            'rpe' => 8,
        ]);

        $workoutId = (int) $result['body']['checkIn']['workoutId'];
        $this->assertSame('modified', $result['body']['checkIn']['status']);
        $this->assertDatabaseHas('plan_compliance_v1', [
            'workout_id' => $workoutId,
            'status' => 'MAJOR_DEVIATION',
            'flag_overshoot_duration' => true,
        ]);
    }

    public function test_skipped_check_in_removes_prior_synthetic_workout(): void
    {
        $service = app(ManualCheckInService::class);

        $done = $service->upsert(1, [
            'plannedSessionDate' => '2026-04-29',
            'status' => 'done',
            'plannedDurationMin' => 40,
            'durationMin' => 40,
        ]);
        $workoutId = (int) $done['body']['checkIn']['workoutId'];
        $this->assertNotNull(Workout::find($workoutId));

        $skipped = $service->upsert(1, [
            'plannedSessionDate' => '2026-04-29',
            'status' => 'skipped',
            'skipReason' => 'no_time',
        ]);

        $this->assertSame('skipped', $skipped['body']['checkIn']['status']);
        $this->assertNull($skipped['body']['checkIn']['workoutId']);
        $this->assertNull(Workout::find($workoutId));
        $this->assertDatabaseCount('manual_check_ins', 1);
        $this->assertDatabaseCount('workouts', 0);
    }
}
