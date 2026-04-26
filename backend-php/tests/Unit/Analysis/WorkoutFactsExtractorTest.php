<?php

namespace Tests\Unit\Analysis;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutRawTcx;
use App\Services\Analysis\WorkoutFactsExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutFactsExtractorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'id' => 1,
            'name' => 'F2 Tester',
            'email' => 'f2@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_full_data_run_returns_facts_with_pace_and_hr(): void
    {
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 3600,
                'distanceM' => 10000,
                'sport' => 'run',
                'hr' => ['avgBpm' => 142, 'maxBpm' => 178],
                'avgPaceSecPerKm' => 360,
            ],
            'source' => 'garmin',
            'source_activity_id' => 'garmin-act-1',
            'dedupe_key' => 'f2-full-1',
        ]);

        $facts = (new WorkoutFactsExtractor)->extract($workout)->toArray();

        $this->assertSame((string) $workout->id, $facts['workoutId']);
        $this->assertSame('1', $facts['userId']);
        $this->assertSame('garmin', $facts['source']);
        $this->assertSame('garmin-act-1', $facts['sourceActivityId']);
        $this->assertSame('2026-04-20T10:00:00Z', $facts['startedAt']);
        $this->assertSame(3600, $facts['durationSec']);
        $this->assertSame(10000.0, $facts['distanceMeters']);
        $this->assertSame('run', $facts['sportKind']);
        $this->assertTrue($facts['hasHr']);
        $this->assertSame(142.0, $facts['avgHrBpm']);
        $this->assertSame(178.0, $facts['maxHrBpm']);
        $this->assertSame(360.0, $facts['avgPaceSecPerKm']);
        $this->assertTrue($facts['hasGps']); // garmin source
        $this->assertSame(WorkoutFactsExtractor::EXTRACTOR_VERSION, $facts['extractorVersion']);
    }

    public function test_run_without_pace_in_summary_is_computed_from_duration_and_distance(): void
    {
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 3000,
                'distanceM' => 6000,
                'sport' => 'run',
            ],
            'source' => 'tcx',
            'dedupe_key' => 'f2-pace-calc',
        ]);

        $facts = (new WorkoutFactsExtractor)->extract($workout)->toArray();

        // 3000s / 6km = 500 s/km
        $this->assertSame(500.0, $facts['avgPaceSecPerKm']);
    }

    public function test_workout_without_hr_returns_has_hr_false_and_null_values(): void
    {
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-21T08:00:00Z',
                'durationSec' => 1800,
                'distanceM' => 4000,
                'sport' => 'run',
            ],
            'source' => 'manual',
            'dedupe_key' => 'f2-no-hr',
        ]);

        $facts = (new WorkoutFactsExtractor)->extract($workout)->toArray();

        $this->assertFalse($facts['hasHr']);
        $this->assertNull($facts['avgHrBpm']);
        $this->assertNull($facts['maxHrBpm']);
        $this->assertSame(0, $facts['hrSampleCount']);
    }

    public function test_manual_workout_without_distance_does_not_invent_pace(): void
    {
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-22T18:00:00Z',
                'durationSec' => 1800,
                'distanceM' => 0,
                'sport' => 'run',
            ],
            'source' => 'manual',
            'dedupe_key' => 'f2-no-distance',
        ]);

        $facts = (new WorkoutFactsExtractor)->extract($workout)->toArray();

        $this->assertNull($facts['distanceMeters']);
        $this->assertNull($facts['avgPaceSecPerKm']);
        $this->assertSame('manual', $facts['source']);
        $this->assertFalse($facts['hasGps']);
    }

    public function test_legacy_manual_upload_source_is_normalized_to_tcx(): void
    {
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-23T07:00:00Z',
                'durationSec' => 2400,
                'distanceM' => 5000,
                'sport' => 'run',
            ],
            'source' => 'MANUAL_UPLOAD',
            'dedupe_key' => 'f2-legacy-source',
        ]);

        $facts = (new WorkoutFactsExtractor)->extract($workout)->toArray();

        $this->assertSame('tcx', $facts['source']);
    }

    public function test_raw_tcx_relation_sets_has_gps_and_raw_ref(): void
    {
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-24T09:00:00Z',
                'durationSec' => 1800,
                'distanceM' => 5000,
                'sport' => 'run',
            ],
            'source' => 'tcx',
            'dedupe_key' => 'f2-with-raw',
        ]);
        WorkoutRawTcx::create([
            'workout_id' => $workout->id,
            'xml' => '<TrainingCenterDatabase></TrainingCenterDatabase>',
            'created_at' => now(),
        ]);

        $facts = (new WorkoutFactsExtractor)->extract($workout->fresh())->toArray();

        $this->assertTrue($facts['hasGps']);
        $this->assertNotNull($facts['rawProviderRefs']['rawTcxId']);
    }

    public function test_unknown_sport_falls_back_to_other(): void
    {
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-25T17:00:00Z',
                'durationSec' => 3600,
                'distanceM' => 30000,
                'sport' => 'swim',
            ],
            'source' => 'garmin',
            'dedupe_key' => 'f2-other-sport',
        ]);

        $facts = (new WorkoutFactsExtractor)->extract($workout)->toArray();

        $this->assertSame('other', $facts['sportKind']);
        // pace tylko dla biegow / chodu, nie dla swim/bike
        $this->assertNull($facts['avgPaceSecPerKm']);
    }

    public function test_perceived_effort_from_meta_is_clamped_to_valid_range(): void
    {
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-26T06:00:00Z',
                'durationSec' => 1500,
                'distanceM' => 4000,
                'sport' => 'run',
            ],
            'workout_meta' => ['perceivedEffort' => 7],
            'source' => 'manual',
            'dedupe_key' => 'f2-effort-ok',
        ]);

        $facts = (new WorkoutFactsExtractor)->extract($workout)->toArray();

        $this->assertSame(7, $facts['perceivedEffort']);

        $workout2 = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-26T07:00:00Z',
                'durationSec' => 1500,
                'distanceM' => 4000,
                'sport' => 'run',
            ],
            'workout_meta' => ['perceivedEffort' => 99],
            'source' => 'manual',
            'dedupe_key' => 'f2-effort-bad',
        ]);

        $facts2 = (new WorkoutFactsExtractor)->extract($workout2)->toArray();
        $this->assertNull($facts2['perceivedEffort']);
    }
}
