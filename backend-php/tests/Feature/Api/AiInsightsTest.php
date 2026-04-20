<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiInsightsTest extends TestCase
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

    public function test_ai_insights_returns_payload_and_cache_contract(): void
    {
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 1800,
                'distanceM' => 5000,
            ],
            'source' => 'manual',
            'source_activity_id' => 'ai-insights-test',
            'dedupe_key' => 'manual:ai-insights-test',
            'workout_meta' => [
                'planCompliance' => 'planned',
                'rpe' => 5,
                'fatigueFlag' => false,
                'note' => 'ok',
            ],
        ]);

        $first = $this->getJson('/api/ai/insights?days=28');
        $first->assertOk();
        $first->assertHeader('x-ai-cache', 'miss');
        $first->assertJsonStructure([
            'payload' => ['generatedAtIso', 'windowDays', 'summary', 'risks', 'questions', 'confidence'],
            'cache',
        ]);
        $first->assertJsonPath('cache', 'miss');

        $second = $this->getJson('/api/ai/insights?days=28');
        $second->assertOk();
        $second->assertHeader('x-ai-cache', 'hit');
        $second->assertJsonPath('cache', 'hit');
    }
}
