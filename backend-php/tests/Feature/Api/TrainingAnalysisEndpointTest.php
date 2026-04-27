<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrainingAnalysisEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
        Carbon::setTestNow('2026-04-27T10:00:00Z');

        User::create([
            'id' => 1,
            'name' => 'F4 User',
            'email' => 'f4@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_training_analysis_endpoint_returns_contract_and_persists_snapshot(): void
    {
        $this->createWorkout('f4-contract-1', Carbon::now()->subDays(2)->toIso8601String());

        $response = $this->getJson('/api/me/training-analysis?days=28');

        $response->assertOk();
        $response->assertHeader('X-Training-Analysis-Cache', 'miss');
        $response->assertJsonStructure([
            'userId',
            'computedAt',
            'windowDays',
            'serviceVersion',
            'facts',
            'hrZones',
            'confidence',
            'missingData',
            'planImplications',
        ]);

        $this->assertSame('1', $response->json('userId'));
        $this->assertSame(28, $response->json('windowDays'));
        $this->assertSame(1, $response->json('facts.workoutCount'));

        $rows = DB::table('training_analysis_snapshots')->where('user_id', 1)->get();
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame(28, (int) $row->window_days);
        $this->assertSame($response->json('serviceVersion'), $row->service_version);
        $this->assertSame($response->json('computedAt'), $row->computed_at_iso);

        $snapshot = json_decode((string) $row->snapshot_json, true);
        $this->assertSame($response->json('facts.workoutCount'), $snapshot['facts']['workoutCount']);
    }

    public function test_training_analysis_endpoint_uses_cache_for_same_user_window_and_version(): void
    {
        $this->createWorkout('f4-cache-1', Carbon::now()->subDays(2)->toIso8601String());

        $first = $this->getJson('/api/me/training-analysis?days=28');
        $first->assertOk();
        $first->assertHeader('X-Training-Analysis-Cache', 'miss');
        $this->assertSame(1, DB::table('training_analysis_snapshots')->count());

        Carbon::setTestNow('2026-04-27T10:01:00Z');
        $this->createWorkout('f4-cache-2', Carbon::now()->subDay()->toIso8601String());

        $second = $this->getJson('/api/me/training-analysis?days=28');
        $second->assertOk();
        $second->assertHeader('X-Training-Analysis-Cache', 'hit');

        $this->assertSame(1, DB::table('training_analysis_snapshots')->count());
        $this->assertSame($first->json('computedAt'), $second->json('computedAt'));
        $this->assertSame(1, $second->json('facts.workoutCount'));
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
