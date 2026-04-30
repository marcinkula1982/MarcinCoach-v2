<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserProfile;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualCheckInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'id' => 1,
            'name' => 'Manual User',
            'email' => 'manual@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_done_manual_check_in_creates_workout_without_tcx_and_is_idempotent(): void
    {
        $payload = [
            'plannedSessionDate' => '2026-04-29',
            'status' => 'done',
            'plannedDurationMin' => 40,
            'durationMin' => 42,
            'rpe' => 5,
            'note' => 'Easy run, no file.',
            'plannedSession' => [
                'type' => 'easy',
                'durationMin' => 40,
                'intensityHint' => 'Z2',
            ],
        ];

        $first = $this->postJson('/api/workouts/manual-check-in', $payload);
        $first->assertCreated();
        $first->assertJsonPath('created', true);
        $first->assertJsonPath('checkIn.status', 'done');
        $this->assertNotNull($first->json('checkIn.workoutId'));

        $workoutId = (int) $first->json('checkIn.workoutId');
        $workout = Workout::findOrFail($workoutId);
        $this->assertSame('MANUAL_CHECK_IN', $workout->source);
        $this->assertSame('done', $workout->workout_meta['manualCheckInStatus']);
        $this->assertSame(2520, $workout->summary['durationSec']);
        $this->assertDatabaseCount('manual_check_ins', 1);
        $this->assertDatabaseCount('workouts', 1);
        $this->assertDatabaseCount('workout_raw_tcx', 0);

        $second = $this->postJson('/api/workouts/manual-check-in', $payload + ['note' => 'Second click']);
        $second->assertOk();
        $second->assertJsonPath('created', false);
        $second->assertJsonPath('checkIn.id', $first->json('checkIn.id'));
        $second->assertJsonPath('checkIn.workoutId', $workoutId);
        $this->assertDatabaseCount('manual_check_ins', 1);
        $this->assertDatabaseCount('workouts', 1);
    }

    public function test_modified_manual_check_in_stores_rpe_pain_and_low_data_feedback(): void
    {
        $response = $this->postJson('/api/workouts/manual-check-in', [
            'plannedSessionDate' => '2026-04-29',
            'plannedSessionId' => 'plan-20260429-easy',
            'status' => 'modified',
            'plannedDurationMin' => 45,
            'actualDurationMin' => 25,
            'rpe' => 8,
            'painFlag' => true,
            'painNote' => 'Left knee felt tight.',
            'modificationReason' => 'Cut short when pain appeared.',
            'plannedSession' => [
                'type' => 'easy',
                'durationMin' => 45,
                'intensityHint' => 'Z2',
            ],
        ]);

        $response->assertCreated();
        $workoutId = (int) $response->json('checkIn.workoutId');
        $workout = Workout::findOrFail($workoutId);

        $this->assertSame('modified', $response->json('checkIn.status'));
        $this->assertSame('modified', $workout->workout_meta['planCompliance']);
        $this->assertSame(8, $workout->workout_meta['rpe']);
        $this->assertTrue($workout->workout_meta['painFlag']);
        $this->assertDatabaseHas('plan_compliance_v1', [
            'workout_id' => $workoutId,
            'status' => 'MAJOR_DEVIATION',
        ]);
        $this->assertTrue((bool) UserProfile::where('user_id', 1)->value('has_current_pain'));

        $feedback = $this->postJson("/api/workouts/{$workoutId}/feedback/generate");
        $feedback->assertOk();
        $feedback->assertJsonPath('confidence', 'low');
        $feedback->assertJsonPath('summary.avgPaceSecPerKm', null);
        $feedback->assertJsonPath('planImpact.warnings.painFlag', true);
        $feedback->assertJsonPath('planImpact.warnings.highRpe', true);
        $this->assertNotContains('Tetno wygladalo mniej stabilnie - mozliwy dryf, zmeczenie albo warunki dnia.', $feedback->json('deviations'));
        $this->assertNotContains('Tempo bylo zmienne; warto sprawdzic trase, wiatr, przewyzszenia albo RPE.', $feedback->json('deviations'));
    }

    public function test_skipped_manual_check_in_closes_day_without_zero_workout(): void
    {
        $response = $this->postJson('/api/workouts/manual-check-in', [
            'plannedSessionDate' => '2026-04-29',
            'status' => 'skipped',
            'plannedDurationMin' => 60,
            'skipReason' => 'pain',
            'note' => 'No run today.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('checkIn.status', 'skipped');
        $response->assertJsonPath('checkIn.workoutId', null);
        $response->assertJsonPath('checkIn.painFlag', true);
        $this->assertDatabaseCount('manual_check_ins', 1);
        $this->assertDatabaseCount('workouts', 0);
        $this->assertTrue((bool) UserProfile::where('user_id', 1)->value('has_current_pain'));

        $second = $this->postJson('/api/workouts/manual-check-in', [
            'plannedSessionDate' => '2026-04-29',
            'status' => 'skipped',
            'plannedDurationMin' => 60,
            'skipReason' => 'fatigue',
        ]);

        $second->assertOk();
        $second->assertJsonPath('checkIn.id', $response->json('checkIn.id'));
        $this->assertDatabaseCount('manual_check_ins', 1);
        $this->assertDatabaseCount('workouts', 0);
        $this->assertSame(0, DB::table('workouts')->count());
    }
}
