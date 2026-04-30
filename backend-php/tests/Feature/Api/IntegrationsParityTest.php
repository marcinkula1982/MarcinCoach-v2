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
        $this->assertSame(1, Cache::get("strava:oauth:state:{$state}")['userId'] ?? null);

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

    public function test_strava_browser_callback_redirects_to_frontend_without_session_headers(): void
    {
        putenv('FRONTEND_URL=https://coach.example');
        putenv('STRAVA_CLIENT_ID=123');
        putenv('STRAVA_CLIENT_SECRET=abc');
        putenv('STRAVA_REDIRECT_URI=http://localhost:8000/api/integrations/strava/callback');

        $connect = $this->postJson('/api/integrations/strava/connect');
        $state = (string) $connect->json('state');

        Http::fake([
            'https://www.strava.com/oauth/token' => Http::response([
                'access_token' => 'token-1',
                'refresh_token' => 'refresh-1',
                'expires_at' => now()->addHour()->timestamp,
                'athlete' => ['id' => 42],
                'scope' => 'activity:read_all',
            ], 200),
        ]);

        $callback = $this->get('/api/integrations/strava/callback?code=code-1&state=' . $state);
        $callback->assertRedirect('https://coach.example?integration=strava&status=connected');
    }

    public function test_strava_refreshes_expired_access_token_before_sync(): void
    {
        putenv('STRAVA_CLIENT_ID=123');
        putenv('STRAVA_CLIENT_SECRET=abc');

        \App\Models\IntegrationAccount::create([
            'user_id' => 1,
            'provider' => 'strava',
            'external_user_id' => '42',
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-old',
            'access_token_expires_at' => now()->subMinute(),
            'status' => 'connected',
        ]);

        Http::fake([
            'https://www.strava.com/oauth/token' => Http::response([
                'access_token' => 'token-new',
                'refresh_token' => 'refresh-new',
                'expires_at' => now()->addHour()->timestamp,
            ], 200),
            'https://www.strava.com/api/v3/athlete/activities*' => Http::response([], 200),
        ]);

        $sync = $this->postJson('/api/integrations/strava/sync', [
            'fromIso' => '2026-04-01',
        ]);

        $sync->assertOk();
        $this->assertDatabaseHas('integration_accounts', [
            'provider' => 'strava',
            'access_token' => 'token-new',
            'refresh_token' => 'refresh-new',
            'status' => 'connected',
        ]);

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://www.strava.com/oauth/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-old'
        );
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
            'https://connector.local/v1/garmin/workouts' => Http::response([
                'connectorMode' => 'stub',
                'status' => 'scheduled',
                'workoutId' => 'workout-1',
                'scheduledDate' => '2026-04-27',
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

        $send = $this->postJson('/api/integrations/garmin/workouts/send', [
            'date' => '2026-04-27',
            'session' => [
                'day' => 'mon',
                'type' => 'easy',
                'durationMin' => 40,
                'intensityHint' => 'Z2',
                'notes' => ['keep it easy'],
            ],
        ]);
        $send->assertOk();
        $send->assertJsonPath('status', 'scheduled');
        $send->assertJsonPath('workoutId', 'workout-1');

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://connector.local/v1/garmin/workouts'
            && $request['userRef'] === '1'
            && $request['date'] === '2026-04-27'
            && $request['type'] === 'easy'
            && $request['durationMin'] === 40
        );
    }

    public function test_integrations_status_returns_all_providers(): void
    {
        $status = $this->getJson('/api/integrations/status');
        $status->assertOk();
        $status->assertJsonStructure(['integrations']);

        $providers = collect($status->json('integrations'))->pluck('provider')->all();
        $this->assertContains('garmin', $providers);
        $this->assertContains('strava', $providers);
        $this->assertContains('suunto_sports_tracker', $providers);

        // Each entry has required fields
        foreach ($status->json('integrations') as $item) {
            $this->assertArrayHasKey('provider', $item);
            $this->assertArrayHasKey('connected', $item);
            $this->assertArrayHasKey('lastSyncAt', $item);
        }
    }

    public function test_integrations_status_reflects_connected_strava(): void
    {
        \App\Models\IntegrationAccount::create([
            'user_id' => 1,
            'provider' => 'strava',
            'status' => 'connected',
            'last_sync_at' => now()->subHour(),
        ]);

        $status = $this->getJson('/api/integrations/status');
        $status->assertOk();

        $strava = collect($status->json('integrations'))->firstWhere('provider', 'strava');
        $this->assertTrue($strava['connected']);
        $this->assertNotNull($strava['lastSyncAt']);
    }

    public function test_disconnect_integration_removes_account(): void
    {
        \App\Models\IntegrationAccount::create([
            'user_id' => 1,
            'provider' => 'garmin',
            'status' => 'connected',
        ]);

        $this->assertDatabaseHas('integration_accounts', ['user_id' => 1, 'provider' => 'garmin']);

        $resp = $this->deleteJson('/api/integrations/garmin');
        $resp->assertOk();
        $resp->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('integration_accounts', ['user_id' => 1, 'provider' => 'garmin']);

        // Status now shows disconnected
        $status = $this->getJson('/api/integrations/status');
        $garmin = collect($status->json('integrations'))->firstWhere('provider', 'garmin');
        $this->assertFalse($garmin['connected']);
    }

    public function test_disconnect_unknown_provider_returns_422(): void
    {
        $resp = $this->deleteJson('/api/integrations/unknown_provider');
        $resp->assertStatus(422);
    }

    public function test_garmin_connector_error_payload_is_preserved(): void
    {
        putenv('GARMIN_CONNECTOR_BASE_URL=https://connector.local');
        putenv('GARMIN_CONNECTOR_API_KEY=secret-key');

        Http::fake([
            'https://connector.local/v1/garmin/sync' => Http::response([
                'detail' => [
                    'error' => 'GARMIN_MFA_REQUIRED',
                    'message' => 'Set GARMIN_MFA_CODE for the first Garmin login.',
                ],
            ], 409),
        ]);

        $sync = $this->postJson('/api/integrations/garmin/sync');
        $sync->assertStatus(502);
        $sync->assertJsonPath('error', 'GARMIN_MFA_REQUIRED');

        $this->assertDatabaseHas('integration_sync_runs', [
            'provider' => 'garmin',
            'status' => 'failed',
            'error_code' => 'GARMIN_MFA_REQUIRED',
        ]);
    }
}
