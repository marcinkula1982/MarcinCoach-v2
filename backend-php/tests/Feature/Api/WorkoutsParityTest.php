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
        $create = $this->postJson('/api/workouts', [
            'tcxRaw' => '<TrainingCenterDatabase />',
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'trimmed' => ['durationSec' => 1800, 'distanceM' => 5000],
                'original' => ['durationSec' => 1800, 'distanceM' => 5000],
                'intensity' => ['z1Sec' => 600, 'z2Sec' => 600, 'z3Sec' => 300, 'z4Sec' => 200, 'z5Sec' => 100],
                'totalPoints' => 100,
                'selectedPoints' => 100,
            ],
        ]);
        $create->assertOk();
        $create->assertJsonStructure(['id', 'userId', 'action', 'kind', 'summary', 'createdAt']);

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

        $summaryV2 = $this->getJson('/api/workouts/analytics/summary-v2');
        $summaryV2->assertOk();
        $summaryV2->assertJsonStructure(['totals' => ['workouts', 'distanceKm', 'durationMin'], 'byWeek', 'byDay']);
    }
}
