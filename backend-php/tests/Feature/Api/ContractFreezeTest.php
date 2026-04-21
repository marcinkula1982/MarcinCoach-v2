<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract freeze tests for the 4 public coaching endpoints.
 *
 * Purpose: Fail immediately if the top-level response shape of any
 * public endpoint changes unexpectedly. These tests are the go/no-go
 * gate for cutover readiness (see docs/php-only-cutover-checklist.md).
 *
 * Covered endpoints:
 *   - GET /api/weekly-plan
 *   - GET /api/training-signals
 *   - GET /api/training-adjustments
 *   - GET /api/training-context
 *
 * Rules:
 *   - assertJsonStructure checks REQUIRED top-level keys only.
 *   - assertJsonMissing checks FORBIDDEN keys (removed drifts).
 *   - Any new top-level key must be explicitly added here AND reviewed
 *     in docs/adr/ before merging.
 */
class ContractFreezeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);

        User::create([
            'id' => 1,
            'name' => 'Contract Test User',
            'email' => 'contract@example.com',
            'password' => bcrypt('password'),
        ]);

        // Provide baseline workout so planning logic has signal input.
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 2400,
                'distanceM' => 6000,
                'intensity' => 28,
            ],
            'source' => 'MANUAL_UPLOAD',
            'dedupe_key' => 'contract-freeze-baseline',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/weekly-plan
    // -------------------------------------------------------------------------

    public function test_weekly_plan_contract_top_level_shape(): void
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
    }

    public function test_weekly_plan_contract_sessions_shape(): void
    {
        $response = $this->getJson('/api/weekly-plan');

        $response->assertOk();

        $sessions = $response->json('sessions');
        $this->assertIsArray($sessions);
        $this->assertNotEmpty($sessions);

        foreach ($sessions as $session) {
            $this->assertArrayHasKey('day', $session, 'Each session must have a day key');
            $this->assertArrayHasKey('type', $session, 'Each session must have a type key');
            $this->assertArrayHasKey('durationMin', $session, 'Each session must have a durationMin key');
        }
    }

    public function test_weekly_plan_contract_summary_shape(): void
    {
        $response = $this->getJson('/api/weekly-plan');

        $response->assertOk();
        $response->assertJsonStructure([
            'summary' => [
                'totalDurationMin',
                'qualitySessions',
                'longRunDay',
            ],
        ]);
    }

    public function test_weekly_plan_contract_no_forbidden_keys_in_sessions(): void
    {
        // These keys were removed from the public contract after M3 drift correction.
        $response = $this->getJson('/api/weekly-plan');

        $response->assertOk();

        $sessions = $response->json('sessions');
        foreach ($sessions as $session) {
            $this->assertArrayNotHasKey(
                'techniqueFocus',
                $session,
                'techniqueFocus must not appear in public session response (removed in M3 drift fix)'
            );
            $this->assertArrayNotHasKey(
                'surfaceHint',
                $session,
                'surfaceHint must not appear in public session response (removed in M3 drift fix)'
            );
        }
    }

    public function test_weekly_plan_contract_applied_adjustments_is_array_of_strings(): void
    {
        $response = $this->getJson('/api/weekly-plan');

        $response->assertOk();

        $codes = $response->json('appliedAdjustmentsCodes');
        $this->assertIsArray($codes);
        foreach ($codes as $code) {
            $this->assertIsString($code, 'Each appliedAdjustmentsCode must be a string');
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/training-signals
    // -------------------------------------------------------------------------

    public function test_training_signals_contract_top_level_shape(): void
    {
        $response = $this->getJson('/api/training-signals');

        $response->assertOk();
        $response->assertJsonStructure([
            'generatedAtIso',
            'windowDays',
            'windowStart',
            'windowEnd',
            'weeklyLoad',
            'rolling4wLoad',
            'buckets',
            'longRun',
            'flags',
            'totalWorkouts',
        ]);
    }

    public function test_training_signals_contract_buckets_shape(): void
    {
        $response = $this->getJson('/api/training-signals');

        $response->assertOk();
        $response->assertJsonStructure([
            'buckets' => [
                'z1Sec',
                'z2Sec',
                'z3Sec',
                'z4Sec',
                'z5Sec',
                'totalSec',
            ],
        ]);
    }

    public function test_training_signals_contract_flags_shape(): void
    {
        $response = $this->getJson('/api/training-signals');

        $response->assertOk();
        $response->assertJsonStructure([
            'flags' => [
                'injuryRisk',
                'fatigue',
            ],
        ]);
    }

    public function test_training_signals_contract_long_run_shape(): void
    {
        $response = $this->getJson('/api/training-signals');

        $response->assertOk();
        $response->assertJsonStructure([
            'longRun' => [
                'exists',
                'distanceKm',
            ],
        ]);
    }

    public function test_training_signals_contract_no_adaptation_in_public_response(): void
    {
        // `adaptation` is an internal field, removed from the public contract in M4 drift fix.
        $response = $this->getJson('/api/training-signals');

        $response->assertOk();
        $this->assertArrayNotHasKey(
            'adaptation',
            $response->json(),
            'adaptation must not appear in public /api/training-signals response (removed in M4 drift fix)'
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/training-adjustments
    // -------------------------------------------------------------------------

    public function test_training_adjustments_contract_top_level_shape(): void
    {
        $response = $this->getJson('/api/training-adjustments');

        $response->assertOk();
        $response->assertJsonStructure([
            'generatedAtIso',
            'windowDays',
            'adjustments',
        ]);
    }

    public function test_training_adjustments_contract_adjustments_is_array(): void
    {
        $response = $this->getJson('/api/training-adjustments');

        $response->assertOk();

        $adjustments = $response->json('adjustments');
        $this->assertIsArray($adjustments);
    }

    public function test_training_adjustments_contract_each_adjustment_has_required_keys(): void
    {
        // Trigger at least one adjustment (fatigue via load spike would be complex to set up;
        // ensure no pain / surface constraint scenario produces the expected shape).
        $this->putJson('/api/me/profile', [
            'surfaces' => ['avoidAsphalt' => true],
        ]);

        $response = $this->getJson('/api/training-adjustments');

        $response->assertOk();

        $adjustments = $response->json('adjustments');
        $this->assertIsArray($adjustments);
        $this->assertNotEmpty($adjustments, 'Expected at least one adjustment (surface_constraint)');

        foreach ($adjustments as $adj) {
            $this->assertArrayHasKey('code', $adj);
            $this->assertArrayHasKey('severity', $adj);
            $this->assertArrayHasKey('rationale', $adj);
            $this->assertArrayHasKey('evidence', $adj);
            $this->assertIsString($adj['code']);
            $this->assertContains($adj['severity'], ['low', 'medium', 'high']);
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/training-context
    // -------------------------------------------------------------------------

    public function test_training_context_contract_top_level_shape(): void
    {
        $response = $this->getJson('/api/training-context');

        $response->assertOk();
        $response->assertJsonStructure([
            'generatedAtIso',
            'windowDays',
            'signals',
            'profile',
        ]);
    }

    public function test_training_context_contract_signals_has_required_keys(): void
    {
        $response = $this->getJson('/api/training-context');

        $response->assertOk();
        $response->assertJsonStructure([
            'signals' => [
                'weeklyLoad',
                'rolling4wLoad',
                'buckets',
                'longRun',
                'flags',
                'totalWorkouts',
            ],
        ]);
    }

    public function test_training_context_contract_profile_has_required_keys(): void
    {
        $response = $this->getJson('/api/training-context');

        $response->assertOk();

        $profile = $response->json('profile');
        $this->assertIsArray($profile);
        // profile must at minimum expose runningDays (used by WeeklyPlanService)
        $this->assertArrayHasKey('runningDays', $profile);
    }

    public function test_training_context_window_days_matches_query_param(): void
    {
        $response = $this->getJson('/api/training-context?days=14');

        $response->assertOk();
        $this->assertSame(14, $response->json('windowDays'));
    }

    public function test_training_signals_window_days_matches_query_param(): void
    {
        $response = $this->getJson('/api/training-signals?days=14');

        $response->assertOk();
        $this->assertSame(14, $response->json('windowDays'));
    }

    public function test_training_adjustments_window_days_matches_query_param(): void
    {
        $response = $this->getJson('/api/training-adjustments?days=14');

        $response->assertOk();
        $this->assertSame(14, $response->json('windowDays'));
    }

    public function test_weekly_plan_window_days_matches_query_param(): void
    {
        $response = $this->getJson('/api/weekly-plan?days=14');

        $response->assertOk();
        $this->assertSame(14, $response->json('windowDays'));
    }
}
