<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Workout;
use App\Services\CrossSourceWorkoutDedupeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossSourceWorkoutDedupeServiceTest extends TestCase
{
    use RefreshDatabase;

    private CrossSourceWorkoutDedupeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'id' => 1,
            'name' => 'Dedupe User',
            'email' => 'dedupe@example.com',
            'password' => bcrypt('password'),
        ]);
        User::create([
            'id' => 2,
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->service = new CrossSourceWorkoutDedupeService;
    }

    public function test_matches_same_activity_from_another_source_within_tolerances(): void
    {
        $garmin = $this->createWorkout('GARMIN', '2026-10-11T08:30:00Z', 3600, 10000);

        // Strava: +4 min start, +3% duration, -2% distance.
        $match = $this->service->findMatch(1, 'STRAVA', '2026-10-11T08:34:00Z', 3708, 9800);

        $this->assertNotNull($match);
        $this->assertSame($garmin->id, $match->id);
    }

    public function test_compares_start_time_on_utc_epoch_not_iso_string(): void
    {
        // Stored with a +02:00 offset, incoming as Z: the same instant.
        $existing = $this->createWorkout('MANUAL_UPLOAD', '2026-10-11T10:30:00+02:00', 3600, 10000);

        $match = $this->service->findMatch(1, 'GARMIN', '2026-10-11T08:30:00Z', 3600, 10000);

        $this->assertNotNull($match);
        $this->assertSame($existing->id, $match->id);
    }

    public function test_start_difference_above_ten_minutes_does_not_match(): void
    {
        $this->createWorkout('GARMIN', '2026-10-11T08:30:00Z', 3600, 10000);

        $this->assertNull($this->service->findMatch(1, 'STRAVA', '2026-10-11T08:41:00Z', 3600, 10000));
        $this->assertNotNull($this->service->findMatch(1, 'STRAVA', '2026-10-11T08:40:00Z', 3600, 10000));
    }

    public function test_duration_difference_above_five_percent_does_not_match(): void
    {
        $this->createWorkout('GARMIN', '2026-10-11T08:30:00Z', 3600, 10000);

        $this->assertNull($this->service->findMatch(1, 'STRAVA', '2026-10-11T08:30:00Z', 3817, 10000)); // ~6%
        $this->assertNotNull($this->service->findMatch(1, 'STRAVA', '2026-10-11T08:30:00Z', 3780, 10000)); // 5%
    }

    public function test_distance_difference_above_three_percent_does_not_match(): void
    {
        $this->createWorkout('GARMIN', '2026-10-11T08:30:00Z', 3600, 10000);

        $this->assertNull($this->service->findMatch(1, 'STRAVA', '2026-10-11T08:30:00Z', 3600, 10400)); // ~4%
        $this->assertNotNull($this->service->findMatch(1, 'STRAVA', '2026-10-11T08:30:00Z', 3600, 10300)); // 3%
    }

    public function test_distance_zero_on_one_side_only_does_not_match(): void
    {
        $this->createWorkout('GARMIN', '2026-10-11T08:30:00Z', 3600, 0);

        $this->assertNull($this->service->findMatch(1, 'STRAVA', '2026-10-11T08:30:00Z', 3600, 10000));
        $this->assertNotNull($this->service->findMatch(1, 'STRAVA', '2026-10-11T08:30:00Z', 3600, 0));
    }

    public function test_same_source_is_not_matched_here(): void
    {
        $this->createWorkout('GARMIN', '2026-10-11T08:30:00Z', 3600, 10000);

        $this->assertNull($this->service->findMatch(1, 'GARMIN', '2026-10-11T08:30:00Z', 3600, 10000));
    }

    public function test_other_user_is_not_matched(): void
    {
        $this->createWorkout('GARMIN', '2026-10-11T08:30:00Z', 3600, 10000, userId: 2);

        $this->assertNull($this->service->findMatch(1, 'STRAVA', '2026-10-11T08:30:00Z', 3600, 10000));
    }

    public function test_manual_check_in_is_excluded_on_both_sides(): void
    {
        $this->createWorkout('MANUAL_CHECK_IN', '2026-10-11T08:30:00Z', 3600, 10000);
        $this->assertNull($this->service->findMatch(1, 'GARMIN', '2026-10-11T08:30:00Z', 3600, 10000));

        $this->createWorkout('GARMIN', '2026-10-12T08:30:00Z', 3600, 10000);
        $this->assertNull($this->service->findMatch(1, 'MANUAL_CHECK_IN', '2026-10-12T08:30:00Z', 3600, 10000));
    }

    public function test_preview_only_workouts_are_ignored(): void
    {
        $this->createWorkout('MANUAL_UPLOAD', '2026-10-11T08:30:00Z', 3600, 10000, action: 'preview-only');

        $this->assertNull($this->service->findMatch(1, 'GARMIN', '2026-10-11T08:30:00Z', 3600, 10000));
    }

    public function test_first_stored_workout_wins_when_several_match(): void
    {
        $first = $this->createWorkout('GARMIN', '2026-10-11T08:30:00Z', 3600, 10000);
        $this->createWorkout('SUUNTO', '2026-10-11T08:31:00Z', 3600, 10000);

        $match = $this->service->findMatch(1, 'STRAVA', '2026-10-11T08:32:00Z', 3600, 10000);

        $this->assertSame($first->id, $match?->id);
    }

    private function createWorkout(
        string $source,
        string $startTimeIso,
        int $durationSec,
        int $distanceM,
        int $userId = 1,
        string $action = 'save'
    ): Workout {
        static $n = 0;
        $n++;

        return Workout::create([
            'user_id' => $userId,
            'action' => $action,
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => $startTimeIso,
                'durationSec' => $durationSec,
                'distanceM' => $distanceM,
            ],
            'source' => $source,
            'source_activity_id' => "act-{$n}",
            'dedupe_key' => "{$source}:act-{$n}",
        ]);
    }
}
