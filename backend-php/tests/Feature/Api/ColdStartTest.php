<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cold start tests — new user with zero workouts.
 *
 * Purpose: Verify that the system does NOT crash or return 5xx
 * for a user who has just registered and has no training history.
 * This is the first thing every new user encounters after cutover.
 *
 * Expected fallback behaviour:
 *   - weekly-plan: returns a valid plan based solely on profile heuristics.
 *     Sessions array is non-empty. No 500.
 *   - training-signals: returns zero-values, flags false. No 500.
 *   - training-adjustments: returns empty adjustments or surface_constraint only. No 500.
 *   - training-context: returns valid shape with empty signals. No 500.
 *
 * These tests are part of the go/no-go cutover gate.
 * See docs/php-only-cutover-checklist.md — Cold Start Acceptance section.
 */
class ColdStartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);

        // New user — no workouts, no profile customisation.
        User::create([
            'id' => 1,
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_new_user_with_no_workouts_gets_valid_weekly_plan(): void
    {
        $response = $this->getJson('/api/weekly-plan');

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

        $sessions = $response->json('sessions');
        $this->assertIsArray($sessions);
        $this->assertNotEmpty($sessions, 'Cold start must produce at least one session');

        // Must have at least one non-rest session (long run on profile default days).
        $activeSessions = array_filter($sessions, fn ($s) => ($s['type'] ?? 'rest') !== 'rest');
        $this->assertNotEmpty($activeSessions, 'Cold start must produce at least one active session');

        // Summary must be consistent.
        $totalDuration = array_reduce($sessions, fn ($acc, $s) => $acc + (int) ($s['durationMin'] ?? 0), 0);
        $this->assertSame($totalDuration, (int) $response->json('summary.totalDurationMin'));
    }

    public function test_new_user_with_no_workouts_gets_valid_training_signals(): void
    {
        $response = $this->getJson('/api/training-signals');

        $response->assertOk();
        $response->assertJsonStructure([
            'generatedAtIso',
            'windowDays',
            'weeklyLoad',
            'rolling4wLoad',
            'buckets',
            'longRun',
            'flags',
            'totalWorkouts',
        ]);

        // Zero history: loads should be zero, flags false, no long run.
        $this->assertSame(0, (int) $response->json('totalWorkouts'));
        $this->assertSame(0.0, (float) $response->json('weeklyLoad'));
        $this->assertSame(0.0, (float) $response->json('rolling4wLoad'));
        $this->assertFalse((bool) $response->json('flags.injuryRisk'));
        $this->assertFalse((bool) $response->json('flags.fatigue'));
        $this->assertFalse((bool) $response->json('longRun.exists'));

        // adaptation must NOT leak into public response.
        $this->assertArrayNotHasKey('adaptation', $response->json());
    }

    public function test_new_user_with_no_workouts_gets_valid_training_adjustments(): void
    {
        $response = $this->getJson('/api/training-adjustments');

        $response->assertOk();
        $response->assertJsonStructure([
            'generatedAtIso',
            'windowDays',
            'adjustments',
        ]);

        // Cold start adjustments must be an array (may be empty or contain surface_constraint).
        $this->assertIsArray($response->json('adjustments'));
    }

    public function test_new_user_with_no_workouts_gets_valid_training_context(): void
    {
        $response = $this->getJson('/api/training-context');

        $response->assertOk();
        $response->assertJsonStructure([
            'generatedAtIso',
            'windowDays',
            'signals',
            'profile',
        ]);

        $this->assertIsArray($response->json('signals'));
        $this->assertIsArray($response->json('profile'));
    }

    public function test_cold_start_weekly_plan_has_no_quality_sessions(): void
    {
        // With zero workouts: sessionsCount = 0, canQuality = false.
        // Quality session requires >= 3 workouts in window.
        $response = $this->getJson('/api/weekly-plan');

        $response->assertOk();

        $sessions = $response->json('sessions');
        $qualityCount = count(array_filter($sessions, fn ($s) => ($s['type'] ?? '') === 'quality'));
        $this->assertSame(0, $qualityCount, 'Cold start must not produce quality sessions (no training history)');
        $this->assertSame(0, (int) $response->json('summary.qualitySessions'));
    }

    public function test_cold_start_with_max_session_min_profile_caps_sessions(): void
    {
        // Verify that cold start respects maxSessionMin cap even without workout history.
        $this->putJson('/api/me/profile', [
            'availability' => [
                'runningDays' => ['mon', 'wed', 'fri', 'sun'],
                'maxSessionMin' => 40,
            ],
        ]);

        $response = $this->getJson('/api/weekly-plan');

        $response->assertOk();

        foreach ($response->json('sessions') as $session) {
            $this->assertLessThanOrEqual(
                40,
                (int) ($session['durationMin'] ?? 0),
                'Cold start must respect maxSessionMin cap'
            );
        }
    }
}
