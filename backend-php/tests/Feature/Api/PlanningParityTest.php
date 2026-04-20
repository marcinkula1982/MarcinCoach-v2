<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        User::create([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_training_context_and_weekly_plan_endpoints(): void
    {
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 2000,
                'distanceM' => 5500,
                'intensity' => 25,
            ],
            'source' => 'manual',
            'dedupe_key' => 'planning-parity-test',
        ]);

        $ctx = $this->getJson('/api/training-context?days=28');
        $ctx->assertOk();
        $ctx->assertJsonStructure(['generatedAtIso', 'windowDays', 'signals', 'profile']);

        $plan = $this->getJson('/api/weekly-plan?days=28');
        $plan->assertOk();
        $plan->assertJsonStructure([
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
}
