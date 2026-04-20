<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiPlanTest extends TestCase
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

    public function test_post_ai_plan_returns_plan_shape(): void
    {
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 2400,
                'distanceM' => 6000,
                'intensity' => 30,
            ],
            'source' => 'manual',
            'source_activity_id' => 'ai-plan-test',
            'dedupe_key' => 'manual:ai-plan-test',
        ]);

        $res = $this->postJson('/api/ai/plan', ['days' => 28]);
        $res->assertOk();
        $res->assertJsonStructure([
            'provider',
            'generatedAtIso',
            'windowDays',
            'plan' => ['sessions', 'summary', 'rationale', 'appliedAdjustmentsCodes'],
            'adjustments' => ['adjustments'],
            'explanation' => ['titlePl', 'summaryPl', 'sessionNotesPl', 'warningsPl', 'confidence'],
        ]);
    }
}
