<?php

namespace Tests\Feature\Api;

use App\Models\IntegrationAccount;
use App\Models\IntegrationSyncRun;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuuntoSportsTrackerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'id' => 1,
            'name' => 'marcin',
            'email' => 'marcin@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->setEnv('SUUNTO_SPORTS_TRACKER_ENABLED', null);
        $this->setEnv('SUUNTO_SPORTS_TRACKER_BASE_URL', null);

        parent::tearDown();
    }

    public function test_unofficial_suunto_sports_tracker_sync_imports_gpx_without_persisting_session_token(): void
    {
        $this->setEnv('SUUNTO_SPORTS_TRACKER_ENABLED', 'true');
        $this->setEnv('SUUNTO_SPORTS_TRACKER_BASE_URL', 'https://sports-tracker.local/apiserver/v1');

        Http::fake([
            'https://sports-tracker.local/apiserver/v1/workouts*' => Http::response([
                'payload' => [
                    [
                        'workoutKey' => 'suunto-workout-1',
                        'activityId' => 1,
                        'description' => 'Easy run from Suunto App',
                    ],
                ],
            ], 200),
            'https://sports-tracker.local/apiserver/v1/workout/exportGpx/suunto-workout-1*' => Http::response(
                $this->gpxFixture(),
                200,
                ['Content-Type' => 'application/gpx+xml'],
            ),
        ]);

        $sessionToken = 'sports-tracker-session-token-123';
        $response = $this->postJson('/api/integrations/suunto/sports-tracker/sync', [
            'sessionToken' => $sessionToken,
            'limit' => 5,
        ]);

        $response->assertOk();
        $response->assertJsonPath('fetched', 1);
        $response->assertJsonPath('downloaded', 1);
        $response->assertJsonPath('imported', 1);
        $response->assertJsonPath('deduped', 0);
        $response->assertJsonPath('failed', 0);
        $response->assertJsonPath('format', 'gpx');

        $this->assertDatabaseHas('workouts', [
            'source' => 'SUUNTO',
            'source_activity_id' => 'suunto-workout-1',
        ]);
        $this->assertDatabaseHas('workout_import_events', [
            'source' => 'SUUNTO',
            'source_activity_id' => 'suunto-workout-1',
            'status' => 'CREATED',
        ]);
        $this->assertDatabaseCount('workout_raw_tcx', 0);

        $workout = Workout::query()->where('source_activity_id', 'suunto-workout-1')->first();
        $this->assertNotNull($workout);
        $summary = is_array($workout->summary) ? $workout->summary : [];
        $this->assertSame('SUUNTO', $summary['provider'] ?? null);
        $this->assertSame('gpx', $summary['fileType'] ?? null);
        $this->assertSame('run', $summary['sport'] ?? null);
        $this->assertSame(140, $summary['hr']['avgBpm'] ?? null);
        $this->assertTrue((bool) ($summary['dataAvailability']['gps'] ?? false));

        $syncRun = IntegrationSyncRun::query()->where('provider', 'suunto_sports_tracker')->first();
        $this->assertNotNull($syncRun);
        $this->assertSame('success', $syncRun->status);
        $this->assertStringNotContainsString($sessionToken, json_encode($syncRun->meta));

        $account = IntegrationAccount::query()->where('provider', 'suunto_sports_tracker')->first();
        $this->assertNotNull($account);
        $this->assertNull($account->access_token);
        $this->assertNull($account->refresh_token);
        $this->assertStringNotContainsString($sessionToken, json_encode($account->meta));

        $duplicate = $this->postJson('/api/integrations/suunto/sports-tracker/sync', [
            'sessionToken' => $sessionToken,
            'limit' => 5,
        ]);
        $duplicate->assertOk();
        $duplicate->assertJsonPath('imported', 0);
        $duplicate->assertJsonPath('deduped', 1);
        $duplicate->assertJsonPath('failed', 0);
        $this->assertDatabaseCount('workouts', 1);
    }

    public function test_unofficial_suunto_sports_tracker_sync_is_disabled_by_default(): void
    {
        Http::fake();

        $response = $this->postJson('/api/integrations/suunto/sports-tracker/sync', [
            'sessionToken' => 'sports-tracker-session-token-123',
            'format' => 'gpx',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'SUUNTO_SPORTS_TRACKER_DISABLED');
        Http::assertNothingSent();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function gpxFixture(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="Suunto Sports Tracker test">
  <trk>
    <trkseg>
      <trkpt lat="52.229700" lon="21.012200">
        <ele>100</ele>
        <time>2026-04-20T10:00:00Z</time>
        <extensions><hr>130</hr></extensions>
      </trkpt>
      <trkpt lat="52.230700" lon="21.013200">
        <ele>105</ele>
        <time>2026-04-20T10:05:00Z</time>
        <extensions><hr>140</hr></extensions>
      </trkpt>
      <trkpt lat="52.231700" lon="21.014200">
        <ele>102</ele>
        <time>2026-04-20T10:10:00Z</time>
        <extensions><hr>150</hr></extensions>
      </trkpt>
    </trkseg>
  </trk>
</gpx>
XML;
    }
}
