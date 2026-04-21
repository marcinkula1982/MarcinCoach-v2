<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutsParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::create([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_list_and_delete_workouts_endpoints(): void
    {
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 1800,
                'distanceM' => 5000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'workouts-parity-test',
        ]);

        $list = $this->getJson('/api/workouts');
        $list->assertOk();
        $list->assertJsonStructure([[
            'id', 'userId', 'action', 'kind', 'summary', 'raceMeta', 'workoutMeta', 'createdAt',
        ]]);

        $delete = $this->deleteJson("/api/workouts/{$workout->id}");
        $delete->assertStatus(204);
        $this->assertDatabaseMissing('workouts', ['id' => $workout->id]);
    }

    public function test_upload_endpoint_accepts_multipart_file(): void
    {
        $tcx = '<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">
  <Activities>
    <Activity Sport="Running">
      <Id>2026-04-20T10:00:00Z</Id>
      <Lap StartTime="2026-04-20T10:00:00Z">
        <TotalTimeSeconds>1800</TotalTimeSeconds>
        <DistanceMeters>5000</DistanceMeters>
      </Lap>
    </Activity>
  </Activities>
</TrainingCenterDatabase>';
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('run.tcx', $tcx);

        $res = $this->post('/api/workouts/upload', ['file' => $file]);
        $res->assertStatus(201);
        $res->assertJsonStructure(['id', 'created']);
    }

    public function test_post_workout_and_analytics_endpoints_contracts(): void
    {
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'trimmed' => ['durationSec' => 1800, 'distanceM' => 5000],
                'original' => ['durationSec' => 1800, 'distanceM' => 5000],
            ],
            'source' => 'MANUAL_UPLOAD',
            'dedupe_key' => 'parity-analytics-seed-1',
        ]);
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-22T10:00:00Z',
                'trimmed' => ['durationSec' => 3600, 'distanceM' => 10000],
                'original' => ['durationSec' => 3600, 'distanceM' => 10000],
            ],
            'source' => 'MANUAL_UPLOAD',
            'dedupe_key' => 'parity-analytics-seed-2',
        ]);

        $analytics = $this->getJson('/api/workouts/analytics');
        $analytics->assertOk();
        $analytics->assertJsonStructure([[
            'workoutId', 'workoutDt', 'distanceKm', 'durationMin', 'type', 'intensity',
        ]]);

        $rows = $this->getJson('/api/workouts/analytics/rows');
        $rows->assertOk();

        $summary = $this->getJson('/api/workouts/analytics/summary');
        $summary->assertOk();
        $summary->assertJsonStructure(['totals' => ['workouts', 'distanceKm', 'durationMin'], 'byWeek', 'byDay']);
        $summary->assertJsonPath('totals.workouts', 2);
        // JSON serialization strips .0 from zero-fraction floats, so compare via cast.
        $this->assertSame(15.0, (float) $summary->json('totals.distanceKm'));
        $this->assertSame(90.0, (float) $summary->json('totals.durationMin'));
        $this->assertNotEmpty($summary->json('byDay'));
        $this->assertNotEmpty($summary->json('byWeek'));

        $summaryV2 = $this->getJson('/api/workouts/analytics/summary-v2');
        $summaryV2->assertOk();
        $summaryV2->assertJsonStructure(['totals' => ['workouts', 'distanceKm', 'durationMin'], 'byWeek', 'byDay']);
        $summaryV2->assertJsonPath('totals.workouts', 2);
        $this->assertSame(15.0, (float) $summaryV2->json('totals.distanceKm'));
        $this->assertSame(90.0, (float) $summaryV2->json('totals.durationMin'));
        $this->assertNotEmpty($summaryV2->json('byDay'));
        $this->assertNotEmpty($summaryV2->json('byWeek'));
    }

    public function test_analytics_summary_exposes_zones_longrun_and_pace(): void
    {
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'sport' => 'run',
                'avgPaceSecPerKm' => 300,
                'trimmed' => ['durationSec' => 3000, 'distanceM' => 10000],
                'original' => ['durationSec' => 3000, 'distanceM' => 10000],
                'intensity' => ['z1Sec' => 600, 'z2Sec' => 1800, 'z3Sec' => 300, 'z4Sec' => 180, 'z5Sec' => 120],
            ],
            'source' => 'MANUAL_UPLOAD',
            'dedupe_key' => 'm2b-analytics-zones-1',
        ]);
        Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-22T10:00:00Z',
                'sport' => 'run',
                'avgPaceSecPerKm' => 360,
                'trimmed' => ['durationSec' => 7200, 'distanceM' => 20000],
                'original' => ['durationSec' => 7200, 'distanceM' => 20000],
                'intensity' => ['z1Sec' => 7200, 'z2Sec' => 0, 'z3Sec' => 0, 'z4Sec' => 0, 'z5Sec' => 0],
            ],
            'source' => 'MANUAL_UPLOAD',
            'dedupe_key' => 'm2b-analytics-zones-2',
        ]);

        $summary = $this->getJson('/api/workouts/analytics/summary');
        $summary->assertOk();

        $summary->assertJsonStructure([
            'byWeek' => [['weekStart', 'workouts', 'distanceKm', 'durationMin', 'zones', 'longRunKm', 'avgPaceSecPerKm']],
            'byDay'  => [['day', 'workouts', 'distanceKm', 'durationMin', 'zones', 'longRunKm', 'avgPaceSecPerKm']],
        ]);

        $week = $summary->json('byWeek.0');
        $this->assertSame(20.0, (float) $week['longRunKm']); // 20km is the longest run that week
        // Distance-weighted pace: (10*300 + 20*360) / 30 = (3000 + 7200) / 30 = 340.
        $this->assertSame(340, (int) $week['avgPaceSecPerKm']);
        $this->assertGreaterThan(0.0, (float) $week['zones']['z1Min']);
        $this->assertGreaterThan(0.0, (float) $week['zones']['z2Min']);
    }
}
