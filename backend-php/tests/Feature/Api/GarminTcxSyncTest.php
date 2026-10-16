<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserProfile;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GarminTcxSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::create(['id' => 1, 'name' => 'marcin', 'email' => 'marcin@example.com', 'password' => bcrypt('password')]);
        putenv('GARMIN_CONNECTOR_BASE_URL=https://connector.local');
        putenv('GARMIN_CONNECTOR_API_KEY=test-key');
        Http::preventStrayRequests();
    }

    private function activity(string $id = '123'): array
    {
        return ['sourceActivityId' => $id, 'startTimeIso' => '2026-10-15T18:47:00Z',
            'durationSec' => 600, 'distanceM' => 1000, 'sport' => 'run', 'name' => 'Opole Bieganie', 'averageHr' => 140];
    }

    private function xml(): string
    {
        return '<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">'
            . '<Activities><Activity Sport="Running"><Id>2026-10-15T18:47:00Z</Id><Lap><TotalTimeSeconds>600</TotalTimeSeconds>'
            . '<DistanceMeters>1000</DistanceMeters><Track>'
            . '<Trackpoint><Time>2026-10-15T18:47:00Z</Time><DistanceMeters>0</DistanceMeters><AltitudeMeters>100</AltitudeMeters>'
            . '<HeartRateBpm><Value>130</Value></HeartRateBpm><Cadence>80</Cadence></Trackpoint>'
            . '<Trackpoint><Time>2026-10-15T18:57:00Z</Time><DistanceMeters>1000</DistanceMeters><AltitudeMeters>110</AltitudeMeters>'
            . '<HeartRateBpm><Value>150</Value></HeartRateBpm><Cadence>90</Cadence></Trackpoint>'
            . '</Track></Lap></Activity></Activities></TrainingCenterDatabase>';
    }

    private function fake(array $activities, string $xml, int $downloadStatus = 200, string $mode = 'live'): void
    {
        Http::fake([
            'https://connector.local/v1/garmin/sync' => Http::response(['connectorMode' => $mode, 'items' => $activities]),
            'https://connector.local/v1/garmin/activities/*/download*' => Http::response($xml, $downloadStatus),
        ]);
    }

    public function test_new_and_existing_workouts_receive_tcx_once_without_duplicates(): void
    {
        $zones = [];
        for ($i = 1; $i <= 5; $i++) {
            $zones["hr_z{$i}_min"] = 50 + ($i - 1) * 30;
            $zones["hr_z{$i}_max"] = 50 + $i * 30;
        }
        UserProfile::create(['user_id' => 1, ...$zones]);
        $existing = Workout::create(['user_id' => 1, 'action' => 'import', 'kind' => 'training', 'source' => 'GARMIN', 'source_activity_id' => '123',
            'dedupe_key' => 'GARMIN:123',
            'summary' => ['startTimeIso' => '2026-10-15T18:47:00Z', 'durationSec' => 600, 'distanceM' => 1000,
                'trimmed' => ['durationSec' => 500, 'distanceM' => 900]],
            'workout_meta' => ['note' => 'keep']]);
        // Same-provider identities stay separate even when timestamps match.
        $this->fake([$this->activity(), $this->activity('124')], $this->xml());
        $this->postJson('/api/integrations/garmin/sync')->assertOk()
            ->assertJsonPath('imported', 1)->assertJsonPath('deduped', 1)
            ->assertJsonPath('tcxEnriched', 2)->assertJsonPath('tcxFailed', 0);

        $existing->refresh();
        $this->assertSame(['durationSec' => 500, 'distanceM' => 900], $existing->summary['trimmed']);
        $this->assertSame(['note' => 'keep'], $existing->workout_meta);
        $this->assertSame(10, $existing->summary['elevationGainMeters']);
        $this->assertSame(140, $existing->summary['hr']['avgBpm']);
        $this->assertSame(600, $existing->summary['intensityBuckets']['z3Sec']);
        $this->assertSame(1, $existing->summary['laps']);
        $this->assertSame('Opole Bieganie', $existing->summary['name']);
        $this->assertDatabaseCount('workout_raw_tcx', 2);
        $this->assertDatabaseHas('training_signals_v2', ['workout_id' => $existing->id]);

        $this->postJson('/api/integrations/garmin/sync')->assertOk()
            ->assertJsonPath('imported', 0)->assertJsonPath('deduped', 2)->assertJsonPath('tcxEnriched', 0);
        $this->assertDatabaseCount('workouts', 2);
        Http::assertSentCount(4); // Two list requests and two downloads, total.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/activities/123/download')
            && $request['userRef'] === '1' && $request['format'] === 'tcx'
            && $request->hasHeader('x-connector-key', 'test-key'));
    }

    public function test_download_failure_preserves_summary_and_can_be_retried(): void
    {
        $downloads = Http::sequence()->push('upstream secret', 503)->push($this->xml());
        Http::fake([
            'https://connector.local/v1/garmin/sync' => Http::response(['items' => [$this->activity()]]),
            'https://connector.local/v1/garmin/activities/*/download*' => $downloads,
        ]);
        $this->postJson('/api/integrations/garmin/sync')->assertOk()->assertJsonPath('tcxFailed', 1);
        $this->assertDatabaseCount('workouts', 1);
        $this->assertDatabaseCount('workout_raw_tcx', 0);
        $this->assertDatabaseHas('integration_sync_runs', ['status' => 'partial']);
        $this->postJson('/api/integrations/garmin/sync')->assertOk()->assertJsonPath('tcxEnriched', 1);
        $this->assertDatabaseCount('workouts', 1);
        $this->assertDatabaseCount('workout_raw_tcx', 1);
    }

    public function test_invalid_or_mismatched_tcx_does_not_replace_existing_data(): void
    {
        foreach (['not XML', str_replace('2026-10-15', '2026-10-01', $this->xml())] as $xml) {
            $workout = Workout::create(['user_id' => 1, 'action' => 'import', 'kind' => 'training', 'source' => 'GARMIN', 'source_activity_id' => (string) strlen($xml),
                'dedupe_key' => 'GARMIN:' . strlen($xml),
                'summary' => ['startTimeIso' => '2026-10-15T18:47:00Z', 'hr' => ['avgBpm' => 140]]]);
            $summary = $workout->summary;
            try {
                app(\App\Services\TcxWorkoutEnrichmentService::class)->enrich($workout, $xml);
                $this->fail('Invalid TCX should be rejected');
            } catch (\InvalidArgumentException) {
                $this->assertSame($summary, $workout->fresh()->summary);
                $this->assertFalse($workout->rawTcx()->exists());
            }
        }
    }

    public function test_download_batch_is_bounded_and_next_sync_picks_up_remaining_files(): void
    {
        $activities = array_map(fn ($i) => $this->activity((string) $i), range(1, 6));
        $this->fake($activities, $this->xml());
        $this->postJson('/api/integrations/garmin/sync')->assertOk()
            ->assertJsonPath('tcxEnriched', 5)->assertJsonPath('tcxPending', 1);
        $this->postJson('/api/integrations/garmin/sync')->assertOk()
            ->assertJsonPath('tcxEnriched', 1)->assertJsonPath('tcxPending', 0);
        $this->assertDatabaseCount('workouts', 6);
        $this->assertDatabaseCount('workout_raw_tcx', 6);
    }

    public function test_stub_sync_does_not_download_files(): void
    {
        $this->fake([$this->activity()], '', mode: 'stub');
        $this->postJson('/api/integrations/garmin/sync')->assertOk()->assertJsonPath('tcxEnriched', 0);
        Http::assertSentCount(1);
    }

    public function test_shared_enrichment_accepts_manual_import_and_large_tcx(): void
    {
        $workout = Workout::create(['user_id' => 1, 'action' => 'save', 'kind' => 'training',
            'source' => 'MANUAL_UPLOAD', 'dedupe_key' => 'MANUAL_UPLOAD:test',
            'summary' => ['startTimeIso' => '2026-10-15T18:47:00Z', 'durationSec' => 600, 'distanceM' => 1000]]);
        $xml = str_replace('<Activities>', '<!--' . str_repeat('padding', 10000) . '--><Activities>', $this->xml());
        app(\App\Services\TcxWorkoutEnrichmentService::class)->enrich($workout, $xml);
        $this->assertSame('MANUAL_UPLOAD', $workout->fresh()->source);
        $this->assertSame($xml, $workout->rawTcx()->firstOrFail()->xml);
        $this->assertSame(1, $workout->fresh()->summary['laps']);
    }

    public function test_pipeline_failure_rolls_back_enrichment_and_raw_file(): void
    {
        $this->mock(\App\Services\TrainingSignalsV2Service::class, function ($mock) {
            $mock->shouldReceive('upsertForWorkout')->once()->andThrow(new \RuntimeException('test failure'));
        });
        $this->fake([$this->activity()], $this->xml());
        $this->postJson('/api/integrations/garmin/sync')->assertOk()->assertJsonPath('tcxFailed', 1);
        $workout = Workout::firstOrFail();
        $this->assertSame(140, $workout->summary['hr']['avgBpm']);
        $this->assertArrayNotHasKey('tcxEnrichmentVersion', $workout->summary);
        $this->assertDatabaseCount('workout_raw_tcx', 0);
    }

    public function test_file_without_hr_samples_keeps_provider_average(): void
    {
        $xml = preg_replace('/<HeartRateBpm>.*?<\/HeartRateBpm>/', '', $this->xml());
        $this->fake([$this->activity()], $xml);
        $this->postJson('/api/integrations/garmin/sync')->assertOk()->assertJsonPath('tcxEnriched', 1);
        $workout = Workout::firstOrFail();
        $this->assertSame(140, $workout->summary['hr']['avgBpm']);
        $this->assertSame(0, $workout->summary['hrSampleCount']);
    }

    public function test_sync_does_not_attach_files_to_cross_source_duplicates(): void
    {
        Workout::create(['user_id' => 1, 'action' => 'save', 'kind' => 'training',
            'source' => 'MANUAL_UPLOAD', 'dedupe_key' => 'MANUAL_UPLOAD:test',
            'summary' => ['startTimeIso' => '2026-10-15T18:47:00Z', 'durationSec' => 600, 'distanceM' => 1000,
                'original' => ['durationSec' => 600, 'distanceM' => 1000]]]);
        $this->fake([$this->activity()], $this->xml());
        $this->postJson('/api/integrations/garmin/sync')->assertOk()
            ->assertJsonPath('deduped', 1)->assertJsonPath('tcxEnriched', 0);
        $this->assertDatabaseCount('workouts', 1);
        $this->assertDatabaseCount('workout_raw_tcx', 0);
        Http::assertSentCount(1);
    }
}
