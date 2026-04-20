<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationsParityTest extends TestCase
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

    public function test_strava_connect_and_callback_and_sync_contract(): void
    {
        config()->set('app.env', 'testing');
        putenv('STRAVA_CLIENT_ID=123');
        putenv('STRAVA_CLIENT_SECRET=abc');
        putenv('STRAVA_REDIRECT_URI=http://localhost:8000/api/integrations/strava/callback');

        $connect = $this->postJson('/api/integrations/strava/connect');
        $connect->assertOk();
        $state = (string) $connect->json('state');
        $this->assertNotSame('', $state);
        $this->assertNotNull(Cache::get("strava:oauth:state:1:{$state}"));

        Http::fake([
            'https://www.strava.com/oauth/token' => Http::response([
                'access_token' => 'token-1',
                'refresh_token' => 'refresh-1',
                'expires_at' => now()->addHour()->timestamp,
                'athlete' => ['id' => 42],
                'scope' => 'activity:read_all',
            ], 200),
            'https://www.strava.com/api/v3/athlete/activities*' => Http::response([
                [
                    'id' => 9001,
                    'start_date' => '2026-04-20T10:00:00Z',
                    'elapsed_time' => 1800,
                    'distance' => 5000,
                ],
            ], 200),
        ]);

        $callback = $this->getJson('/api/integrations/strava/callback?code=code-1&state=' . $state);
        $callback->assertOk();
        $callback->assertJsonPath('ok', true);

        $sync = $this->postJson('/api/integrations/strava/sync');
        $sync->assertOk();
        $sync->assertJsonStructure(['syncRunId', 'fetched', 'imported', 'deduped', 'failed']);
    }

    public function test_garmin_connect_sync_and_status_contract(): void
    {
        putenv('GARMIN_CONNECTOR_BASE_URL=https://connector.local');
        putenv('GARMIN_CONNECTOR_API_KEY=secret-key');

        Http::fake([
            'https://connector.local/v1/garmin/connect/start' => Http::response([
                'accountRef' => 'garmin-1',
                'status' => 'connected',
            ], 200),
            'https://connector.local/v1/garmin/sync' => Http::response([
                'items' => [
                    [
                        'sourceActivityId' => 'garmin-activity-1',
                        'startTimeIso' => '2026-04-20T11:00:00Z',
                        'durationSec' => 2400,
                        'distanceM' => 6500,
                    ],
                ],
            ], 200),
            'https://connector.local/v1/garmin/accounts/1/status' => Http::response([
                'connected' => true,
                'health' => 'ok',
            ], 200),
        ]);

        $connect = $this->postJson('/api/integrations/garmin/connect');
        $connect->assertOk();
        $connect->assertJsonPath('status', 'connected');

        $sync = $this->postJson('/api/integrations/garmin/sync');
        $sync->assertOk();
        $sync->assertJsonStructure(['syncRunId', 'fetched', 'imported', 'deduped', 'failed']);

        $status = $this->getJson('/api/integrations/garmin/status');
        $status->assertOk();
        $status->assertJsonPath('connected', true);
    }
}
