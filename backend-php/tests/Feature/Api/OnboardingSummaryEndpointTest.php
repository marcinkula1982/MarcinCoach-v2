<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OnboardingSummaryEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
        Carbon::setTestNow('2026-04-27T11:00:00Z');

        User::create([
            'id' => 1,
            'name' => 'F5 User',
            'email' => 'f5@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_onboarding_summary_returns_fact_based_cold_start_card(): void
    {
        $response = $this->getJson('/api/me/onboarding-summary?days=28');

        $response->assertOk();
        $response->assertJsonStructure([
            'generatedAtIso',
            'source',
            'analysisComputedAt',
            'windowDays',
            'confidence',
            'headline',
            'lead',
            'highlights',
            'badges',
            'nextSteps',
            'analysisCache',
        ]);
        $response->assertJsonPath('source', 'training_analysis');
        $response->assertJsonPath('windowDays', 28);
        $response->assertJsonPath('confidence', 'low');
        $response->assertJsonPath('headline', 'Profil zapisany. Zaczynamy spokojnie.');
        $response->assertJsonPath('badges.0.code', 'cold_start');
        $response->assertJsonPath('nextSteps.0.code', 'sync_training_data');

        $this->assertSame(1, DB::table('training_analysis_snapshots')->count());
    }

    public function test_onboarding_summary_uses_training_analysis_cache_and_surfaces_real_facts(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->createWorkout("f5-cache-{$i}", Carbon::now()->subDays($i * 3 + 1)->toIso8601String());
        }

        $first = $this->getJson('/api/me/onboarding-summary?days=28');
        $first->assertOk();
        $first->assertJsonPath('analysisCache', 'miss');
        $first->assertJsonPath('headline', 'Mamy solidna baze do personalizacji.');
        $first->assertJsonPath('highlights.0.code', 'workout_count');
        $first->assertJsonPath('highlights.0.value', '8');
        $first->assertJsonPath('badges.0.code', 'data_seen');

        $second = $this->getJson('/api/me/onboarding-summary?days=28');
        $second->assertOk();
        $second->assertJsonPath('analysisCache', 'hit');
        $this->assertSame($first->json('analysisComputedAt'), $second->json('analysisComputedAt'));
        $this->assertSame(1, DB::table('training_analysis_snapshots')->count());
    }

    private function createWorkout(string $dedupeKey, string $startedAt): void
    {
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => $startedAt,
                'durationSec' => 3600,
                'distanceM' => 10000,
                'sport' => 'run',
                'hr' => ['avgBpm' => 145, 'maxBpm' => 176],
                'avgPaceSecPerKm' => 360,
            ],
            'source' => 'manual',
            'dedupe_key' => $dedupeKey,
        ]);
    }
}
