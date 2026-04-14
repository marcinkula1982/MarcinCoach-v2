<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use App\Services\TrainingAlertsV1Service;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrainingAlertsV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a default user for tests
        User::create([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_major_overshoot_generates_critical_alert(): void
    {
        // given
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 7200,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-overshoot',
        ]);

        DB::table('plan_compliance_v1')->insert([
            'workout_id' => $workout->id,
            'expected_duration_sec' => 3600,
            'actual_duration_sec' => 7200,
            'delta_duration_sec' => 3600,
            'duration_ratio' => 2.0,
            'status' => 'MAJOR_DEVIATION',
            'flag_overshoot_duration' => true,
            'flag_undershoot_duration' => false,
            'generated_at' => now(),
        ]);

        $service = app(TrainingAlertsV1Service::class);

        // when
        $service->upsertForWorkout($workout->id);

        // then
        $alert = DB::table('training_alerts_v1')
            ->where('workout_id', $workout->id)
            ->where('code', 'DURATION_MAJOR_OVERSHOOT')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('CRITICAL', $alert->severity);
        
        $payload = json_decode($alert->payload_json, true);
        $this->assertSame(3600, $payload['expectedDurationSec']);
        $this->assertSame(7200, $payload['actualDurationSec']);
        $this->assertSame(3600, $payload['deltaDurationSec']);
        $this->assertEqualsWithDelta(2.0, $payload['durationRatio'], 0.0001);
        $this->assertSame('MAJOR_DEVIATION', $payload['status']);
    }

    public function test_major_undershoot_generates_warning_alert(): void
    {
        // given
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 1800,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-undershoot',
        ]);

        DB::table('plan_compliance_v1')->insert([
            'workout_id' => $workout->id,
            'expected_duration_sec' => 3600,
            'actual_duration_sec' => 1800,
            'delta_duration_sec' => -1800,
            'duration_ratio' => 0.5,
            'status' => 'MAJOR_DEVIATION',
            'flag_overshoot_duration' => false,
            'flag_undershoot_duration' => true,
            'generated_at' => now(),
        ]);

        $service = app(TrainingAlertsV1Service::class);

        // when
        $service->upsertForWorkout($workout->id);

        // then
        $alert = DB::table('training_alerts_v1')
            ->where('workout_id', $workout->id)
            ->where('code', 'DURATION_MAJOR_UNDERSHOOT')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('WARNING', $alert->severity);
        
        $payload = json_decode($alert->payload_json, true);
        $this->assertSame(3600, $payload['expectedDurationSec']);
        $this->assertSame(1800, $payload['actualDurationSec']);
        $this->assertSame(-1800, $payload['deltaDurationSec']);
        $this->assertSame(0.5, $payload['durationRatio']);
        $this->assertSame('MAJOR_DEVIATION', $payload['status']);
    }

    public function test_easy_became_z5_generates_critical_alert(): void
    {
        // given
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 3600,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-easy-z5',
        ]);

        // Dodaj plan_compliance_v1 żeby nie powstał PLAN_MISSING
        DB::table('plan_compliance_v1')->insert([
            'workout_id' => $workout->id,
            'expected_duration_sec' => 3600,
            'actual_duration_sec' => 3600,
            'delta_duration_sec' => 0,
            'duration_ratio' => 1.0,
            'status' => 'OK',
            'flag_overshoot_duration' => false,
            'flag_undershoot_duration' => false,
            'generated_at' => now(),
        ]);

        DB::table('plan_compliance_v2')->insert([
            'workout_id' => $workout->id,
            'expected_hr_zone_min' => 100,
            'expected_hr_zone_max' => 150,
            'actual_hr_z1_sec' => 0,
            'actual_hr_z2_sec' => 0,
            'actual_hr_z3_sec' => 0,
            'actual_hr_z4_sec' => 0,
            'actual_hr_z5_sec' => 3600,
            'high_intensity_sec' => 3600,
            'high_intensity_ratio' => 1.0,
            'status' => 'MAJOR_DEVIATION',
            'flag_easy_became_z5' => true,
            'generated_at' => now(),
        ]);

        $service = app(TrainingAlertsV1Service::class);

        // when
        $service->upsertForWorkout($workout->id);

        // then
        $alert = DB::table('training_alerts_v1')
            ->where('workout_id', $workout->id)
            ->where('code', 'EASY_BECAME_Z5')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('CRITICAL', $alert->severity);
        
        $payload = json_decode($alert->payload_json, true);
        $this->assertSame(100, $payload['expectedHrZoneMin']);
        $this->assertSame(150, $payload['expectedHrZoneMax']);
        $this->assertSame(3600, $payload['actualHrZ5Sec']);
        $this->assertEqualsWithDelta(1.0, $payload['highIntensityRatio'], 0.0001);
    }

    public function test_missing_training_signals_v2_generates_info_alert(): void
    {
        // given
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 3600,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-hr-missing',
        ]);

        // No training_signals_v2 record

        $service = app(TrainingAlertsV1Service::class);

        // when
        $service->upsertForWorkout($workout->id);

        // then
        $alert = DB::table('training_alerts_v1')
            ->where('workout_id', $workout->id)
            ->where('code', 'HR_DATA_MISSING')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('INFO', $alert->severity);
        
        $payload = json_decode($alert->payload_json, true);
        $this->assertSame('missing_hr', $payload['reason']);
    }

    public function test_hr_available_zero_generates_info_alert(): void
    {
        // given
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 3600,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-hr-zero',
        ]);

        DB::table('training_signals_v2')->insert([
            'workout_id' => $workout->id,
            'hr_available' => 0,
            'hr_avg_bpm' => null,
            'hr_max_bpm' => null,
            'hr_z1_sec' => null,
            'hr_z2_sec' => null,
            'hr_z3_sec' => null,
            'hr_z4_sec' => null,
            'hr_z5_sec' => null,
            'generated_at' => now(),
        ]);

        $service = app(TrainingAlertsV1Service::class);

        // when
        $service->upsertForWorkout($workout->id);

        // then
        $alert = DB::table('training_alerts_v1')
            ->where('workout_id', $workout->id)
            ->where('code', 'HR_DATA_MISSING')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('INFO', $alert->severity);
    }

    public function test_plan_missing_generates_info_alert(): void
    {
        // given
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 3600,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-plan-missing',
        ]);

        // No plan_compliance_v1 record

        $service = app(TrainingAlertsV1Service::class);

        // when
        $service->upsertForWorkout($workout->id);

        // then
        $alert = DB::table('training_alerts_v1')
            ->where('workout_id', $workout->id)
            ->where('code', 'PLAN_MISSING')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('INFO', $alert->severity);
        
        $payload = json_decode($alert->payload_json, true);
        $this->assertSame('no_plan_or_no_match', $payload['reason']);
    }

    public function test_plan_unknown_status_generates_info_alert(): void
    {
        // given
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 3600,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-plan-unknown',
        ]);

        DB::table('plan_compliance_v1')->insert([
            'workout_id' => $workout->id,
            'expected_duration_sec' => null,
            'actual_duration_sec' => 3600,
            'delta_duration_sec' => null,
            'duration_ratio' => null,
            'status' => 'UNKNOWN',
            'flag_overshoot_duration' => false,
            'flag_undershoot_duration' => false,
            'generated_at' => now(),
        ]);

        $service = app(TrainingAlertsV1Service::class);

        // when
        $service->upsertForWorkout($workout->id);

        // then
        $alert = DB::table('training_alerts_v1')
            ->where('workout_id', $workout->id)
            ->where('code', 'PLAN_MISSING')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('INFO', $alert->severity);
    }

    public function test_alert_cleanup_removes_inactive_alerts(): void
    {
        // given
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 7200,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-cleanup',
        ]);

        DB::table('plan_compliance_v1')->insert([
            'workout_id' => $workout->id,
            'expected_duration_sec' => 3600,
            'actual_duration_sec' => 7200,
            'delta_duration_sec' => 3600,
            'duration_ratio' => 2.0,
            'status' => 'MAJOR_DEVIATION',
            'flag_overshoot_duration' => true,
            'flag_undershoot_duration' => false,
            'generated_at' => now(),
        ]);

        $service = app(TrainingAlertsV1Service::class);

        // when - pierwsze wywołanie generuje alert
        $service->upsertForWorkout($workout->id);

        // then - alert istnieje
        $alert = DB::table('training_alerts_v1')
            ->where('workout_id', $workout->id)
            ->where('code', 'DURATION_MAJOR_OVERSHOOT')
            ->first();
        $this->assertNotNull($alert);

        // when - zmieniamy dane tak, by reguła nie była spełniona
        DB::table('plan_compliance_v1')
            ->where('workout_id', $workout->id)
            ->update([
                'status' => 'OK',
                'duration_ratio' => 1.0,
            ]);

        // when - ponowne wywołanie
        $service->upsertForWorkout($workout->id);

        // then - alert został usunięty
        $alertAfter = DB::table('training_alerts_v1')
            ->where('workout_id', $workout->id)
            ->where('code', 'DURATION_MAJOR_OVERSHOOT')
            ->first();
        $this->assertNull($alertAfter);
    }

    public function test_endpoint_returns_alerts_for_workout(): void
    {
        // given
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 7200,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-endpoint',
        ]);

        DB::table('plan_compliance_v1')->insert([
            'workout_id' => $workout->id,
            'expected_duration_sec' => 3600,
            'actual_duration_sec' => 7200,
            'delta_duration_sec' => 3600,
            'duration_ratio' => 2.0,
            'status' => 'MAJOR_DEVIATION',
            'flag_overshoot_duration' => true,
            'flag_undershoot_duration' => false,
            'generated_at' => now(),
        ]);

        // Dodaj training_signals_v2 żeby nie powstał HR_DATA_MISSING
        DB::table('training_signals_v2')->insert([
            'workout_id' => $workout->id,
            'hr_available' => 1,
            'hr_avg_bpm' => 150,
            'hr_max_bpm' => 180,
            'hr_z1_sec' => 0,
            'hr_z2_sec' => 0,
            'hr_z3_sec' => 0,
            'hr_z4_sec' => 0,
            'hr_z5_sec' => 0,
            'generated_at' => now(),
        ]);

        $service = app(TrainingAlertsV1Service::class);
        $service->upsertForWorkout($workout->id);

        // when
        $response = $this->getJson("/api/workouts/{$workout->id}/alerts-v1");

        // then
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('DURATION_MAJOR_OVERSHOOT', $data[0]['code']);
        $this->assertSame('CRITICAL', $data[0]['severity']);
        $this->assertArrayHasKey('payloadJson', $data[0]);
        $this->assertArrayHasKey('generatedAtIso', $data[0]);
    }

    public function test_endpoint_returns_empty_array_when_no_alerts(): void
    {
        // given
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 3600,
                'distanceM' => 10000,
            ],
            'source' => 'manual',
            'dedupe_key' => 'test-endpoint-empty',
        ]);

        DB::table('plan_compliance_v1')->insert([
            'workout_id' => $workout->id,
            'expected_duration_sec' => 3600,
            'actual_duration_sec' => 3600,
            'delta_duration_sec' => 0,
            'duration_ratio' => 1.0,
            'status' => 'OK',
            'flag_overshoot_duration' => false,
            'flag_undershoot_duration' => false,
            'generated_at' => now(),
        ]);

        DB::table('training_signals_v2')->insert([
            'workout_id' => $workout->id,
            'hr_available' => 1,
            'hr_avg_bpm' => 150,
            'hr_max_bpm' => 180,
            'hr_z1_sec' => 0,
            'hr_z2_sec' => 0,
            'hr_z3_sec' => 0,
            'hr_z4_sec' => 0,
            'hr_z5_sec' => 0,
            'generated_at' => now(),
        ]);

        $service = app(TrainingAlertsV1Service::class);
        $service->upsertForWorkout($workout->id);

        // when
        $response = $this->getJson("/api/workouts/{$workout->id}/alerts-v1");

        // then
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }

    public function test_endpoint_returns_404_for_nonexistent_workout(): void
    {
        // when
        $response = $this->getJson('/api/workouts/99999/alerts-v1');

        // then
        $response->assertStatus(404);
        $response->assertJson([
            'message' => 'workout not found',
        ]);
    }

    public function test_full_cycle_with_compliance_v1_and_signals_v2_generates_overshoot_and_removes_plan_missing_and_hr_missing(): void
    {
        // given - ustawiamy deterministyczny czas
        $testNow = Carbon::parse('2025-01-01T00:00:00Z');
        Carbon::setTestNow($testNow);

        // Utwórz workout o id 10 - używamy DB::table()->insert() żeby wymusić id
        DB::table('workouts')->insert([
            'id' => 10,
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => json_encode([
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 7592,
                'distanceM' => 15000,
            ]),
            'source' => 'manual',
            'dedupe_key' => 'test-full-cycle',
            'created_at' => $testNow,
            'updated_at' => $testNow,
        ]);

        // Utwórz plan_snapshot obejmujący czas workoutu
        $plannedWorkout = [
            'startTimeIso' => '2025-01-01T10:00:00Z',
            'expectedDurationSec' => 3600,
            'expectedDistanceM' => 10000,
        ];
        
        $snapshotJson = json_encode(['items' => [$plannedWorkout]]);
        
        DB::table('plan_snapshots')->insert([
            'user_id' => 1,
            'snapshot_json' => $snapshotJson,
            'window_start_iso' => '2025-01-01T00:00:00Z',
            'window_end_iso' => '2025-01-01T23:59:59Z',
            'created_at' => $testNow,
        ]);

        // Wstaw plan_compliance_v1 dla workoutu
        DB::table('plan_compliance_v1')->insert([
            'workout_id' => 10,
            'expected_duration_sec' => 3600,
            'actual_duration_sec' => 7592,
            'delta_duration_sec' => 3992,
            'duration_ratio' => 2.1088888889,
            'status' => 'MAJOR_DEVIATION',
            'flag_overshoot_duration' => 1,
            'flag_undershoot_duration' => 0,
            'generated_at' => $testNow,
        ]);

        // Wstaw training_signals_v2 dla workoutu (hr_available = 1)
        DB::table('training_signals_v2')->insert([
            'workout_id' => 10,
            'hr_available' => 1,
            'hr_avg_bpm' => 155,
            'hr_max_bpm' => 180,
            'hr_z1_sec' => 500,
            'hr_z2_sec' => 2000,
            'hr_z3_sec' => 3000,
            'hr_z4_sec' => 1500,
            'hr_z5_sec' => 592,
            'generated_at' => $testNow,
        ]);

        $service = app(TrainingAlertsV1Service::class);

        // when
        $service->upsertForWorkout(10);

        // then - w training_alerts_v1 istnieje rekord DURATION_MAJOR_OVERSHOOT
        $overshootAlert = DB::table('training_alerts_v1')
            ->where('workout_id', 10)
            ->where('code', 'DURATION_MAJOR_OVERSHOOT')
            ->first();

        $this->assertNotNull($overshootAlert, 'DURATION_MAJOR_OVERSHOOT alert should exist');
        $this->assertSame('CRITICAL', $overshootAlert->severity);
        
        $payload = json_decode($overshootAlert->payload_json, true);
        $this->assertSame(3600, $payload['expectedDurationSec']);
        $this->assertSame(7592, $payload['actualDurationSec']);
        $this->assertSame(3992, $payload['deltaDurationSec']);
        $this->assertEqualsWithDelta(2.1088888889, $payload['durationRatio'], 0.0001);
        $this->assertSame('MAJOR_DEVIATION', $payload['status']);

        // then - NIE istnieje rekord PLAN_MISSING
        $planMissingAlert = DB::table('training_alerts_v1')
            ->where('workout_id', 10)
            ->where('code', 'PLAN_MISSING')
            ->first();
        $this->assertNull($planMissingAlert, 'PLAN_MISSING alert should NOT exist when compliance v1 exists');

        // then - NIE istnieje rekord HR_DATA_MISSING
        $hrMissingAlert = DB::table('training_alerts_v1')
            ->where('workout_id', 10)
            ->where('code', 'HR_DATA_MISSING')
            ->first();
        $this->assertNull($hrMissingAlert, 'HR_DATA_MISSING alert should NOT exist when signals v2 with hr_available=1 exists');

        Carbon::setTestNow();
    }

    public function test_cleanup_removes_overshoot_alert_when_condition_no_longer_met(): void
    {
        // given - ustawiamy deterministyczny czas
        $testNow = Carbon::parse('2025-01-01T00:00:00Z');
        Carbon::setTestNow($testNow);

        // Utwórz workout o id 11 - używamy DB::table()->insert() żeby wymusić id
        DB::table('workouts')->insert([
            'id' => 11,
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => json_encode([
                'startTimeIso' => '2025-01-01T10:00:00Z',
                'durationSec' => 7592,
                'distanceM' => 15000,
            ]),
            'source' => 'manual',
            'dedupe_key' => 'test-cleanup-full',
            'created_at' => $testNow,
            'updated_at' => $testNow,
        ]);

        // Utwórz plan_snapshot
        $plannedWorkout = [
            'startTimeIso' => '2025-01-01T10:00:00Z',
            'expectedDurationSec' => 3600,
            'expectedDistanceM' => 10000,
        ];
        
        $snapshotJson = json_encode(['items' => [$plannedWorkout]]);
        
        DB::table('plan_snapshots')->insert([
            'user_id' => 1,
            'snapshot_json' => $snapshotJson,
            'window_start_iso' => '2025-01-01T00:00:00Z',
            'window_end_iso' => '2025-01-01T23:59:59Z',
            'created_at' => $testNow,
        ]);

        // Wstaw plan_compliance_v1 z MAJOR_DEVIATION i overshoot
        DB::table('plan_compliance_v1')->insert([
            'workout_id' => 11,
            'expected_duration_sec' => 3600,
            'actual_duration_sec' => 7592,
            'delta_duration_sec' => 3992,
            'duration_ratio' => 2.1088888889,
            'status' => 'MAJOR_DEVIATION',
            'flag_overshoot_duration' => 1,
            'flag_undershoot_duration' => 0,
            'generated_at' => $testNow,
        ]);

        // Wstaw training_signals_v2
        DB::table('training_signals_v2')->insert([
            'workout_id' => 11,
            'hr_available' => 1,
            'hr_avg_bpm' => 155,
            'hr_max_bpm' => 180,
            'hr_z1_sec' => 500,
            'hr_z2_sec' => 2000,
            'hr_z3_sec' => 3000,
            'hr_z4_sec' => 1500,
            'hr_z5_sec' => 592,
            'generated_at' => $testNow,
        ]);

        $service = app(TrainingAlertsV1Service::class);

        // when - pierwsze wywołanie generuje alert
        $service->upsertForWorkout(11);

        // then - alert istnieje
        $alert = DB::table('training_alerts_v1')
            ->where('workout_id', 11)
            ->where('code', 'DURATION_MAJOR_OVERSHOOT')
            ->first();
        $this->assertNotNull($alert, 'DURATION_MAJOR_OVERSHOOT alert should exist initially');

        // when - zmieniamy dane tak, by reguła nie była spełniona (status='OK', ratio=1.02)
        DB::table('plan_compliance_v1')
            ->where('workout_id', 11)
            ->update([
                'status' => 'OK',
                'duration_ratio' => 1.02,
                'flag_overshoot_duration' => 0,
            ]);

        // when - ponowne wywołanie
        $service->upsertForWorkout(11);

        // then - alert został usunięty
        $alertAfter = DB::table('training_alerts_v1')
            ->where('workout_id', 11)
            ->where('code', 'DURATION_MAJOR_OVERSHOOT')
            ->first();
        $this->assertNull($alertAfter, 'DURATION_MAJOR_OVERSHOOT alert should be deleted when condition is no longer met');

        Carbon::setTestNow();
    }

    public function test_import_creates_workout_generates_alerts(): void
    {
        // given - ustawiamy deterministyczny czas
        $testNow = Carbon::parse('2025-01-01T00:00:00Z');
        Carbon::setTestNow($testNow);

        // when - import workoutu bez plan_compliance_v1 (powinien powstać PLAN_MISSING)
        $response = $this->postJson('/api/workouts/import', [
            'source' => 'garmin',
            'sourceActivityId' => 'test-alerts-import',
            'startTimeIso' => '2025-01-01T10:00:00Z',
            'durationSec' => 3600,
            'distanceM' => 10000,
        ]);

        // then
        $response->assertStatus(201);
        $response->assertJson(['created' => true]);
        $workoutId = $response->json('id');

        // then - alert PLAN_MISSING został wygenerowany
        $alert = DB::table('training_alerts_v1')
            ->where('workout_id', $workoutId)
            ->where('code', 'PLAN_MISSING')
            ->first();

        $this->assertNotNull($alert, 'PLAN_MISSING alert should be generated after import');
        $this->assertSame('INFO', $alert->severity);

        Carbon::setTestNow();
    }

    public function test_import_deduped_does_not_generate_new_alerts(): void
    {
        // given - ustawiamy deterministyczny czas
        $testNow = Carbon::parse('2025-01-01T00:00:00Z');
        Carbon::setTestNow($testNow);

        // Utwórz pierwszy workout z importu
        $firstResponse = $this->postJson('/api/workouts/import', [
            'source' => 'garmin',
            'sourceActivityId' => 'test-deduped-alerts',
            'startTimeIso' => '2025-01-01T10:00:00Z',
            'durationSec' => 3600,
            'distanceM' => 10000,
        ]);

        $firstResponse->assertStatus(201);
        $workoutId = $firstResponse->json('id');

        // Sprawdź ile alertów powstało po pierwszym imporcie
        $alertsAfterFirst = DB::table('training_alerts_v1')
            ->where('workout_id', $workoutId)
            ->count();

        $this->assertGreaterThan(0, $alertsAfterFirst, 'At least one alert should be generated after first import');

        // Pobierz generated_at pierwszego alertu
        $firstAlert = DB::table('training_alerts_v1')
            ->where('workout_id', $workoutId)
            ->first();
        $firstGeneratedAt = $firstAlert->generated_at;

        // when - import tego samego workoutu (DEDUPED)
        $secondResponse = $this->postJson('/api/workouts/import', [
            'source' => 'garmin',
            'sourceActivityId' => 'test-deduped-alerts',
            'startTimeIso' => '2025-01-01T11:00:00Z', // Different time, but same source+activityId
            'durationSec' => 3700,
            'distanceM' => 11000,
        ]);

        $secondResponse->assertStatus(200);
        $secondResponse->assertJson([
            'id' => $workoutId,
            'created' => false,
        ]);

        // then - liczba alertów się nie zmieniła
        $alertsAfterSecond = DB::table('training_alerts_v1')
            ->where('workout_id', $workoutId)
            ->count();

        $this->assertSame($alertsAfterFirst, $alertsAfterSecond, 'Number of alerts should not change after DEDUPED import');

        // then - generated_at się nie zmienił (brak nowych rekordów)
        $secondAlert = DB::table('training_alerts_v1')
            ->where('workout_id', $workoutId)
            ->where('code', $firstAlert->code)
            ->first();

        $this->assertSame($firstGeneratedAt, $secondAlert->generated_at, 'generated_at should not change after DEDUPED import');

        Carbon::setTestNow();
    }

    public function test_import_updated_generates_alerts(): void
    {
        // given - ustawiamy deterministyczny czas
        $testNow = Carbon::parse('2025-01-01T00:00:00Z');
        Carbon::setTestNow($testNow);

        $tcxXml1 = '<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">
  <Activities>
    <Activity Sport="Running">
      <Id>2025-01-01T10:00:00Z</Id>
      <Lap StartTime="2025-01-01T10:00:00Z">
        <TotalTimeSeconds>3600</TotalTimeSeconds>
        <DistanceMeters>10000</DistanceMeters>
      </Lap>
    </Activity>
  </Activities>
</TrainingCenterDatabase>';

        // Utwórz pierwszy workout z TCX
        $firstResponse = $this->postJson('/api/workouts/import', [
            'source' => 'tcx',
            'sourceActivityId' => 'test-updated-alerts',
            'startTimeIso' => '2025-01-01T10:00:00Z',
            'durationSec' => 3600,
            'distanceM' => 10000,
            'rawTcxXml' => $tcxXml1,
        ]);

        $firstResponse->assertStatus(201);
        $workoutId = $firstResponse->json('id');

        $tcxXml2 = '<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">
  <Activities>
    <Activity Sport="Running">
      <Id>2025-01-01T10:00:00Z</Id>
      <Lap StartTime="2025-01-01T10:00:00Z">
        <TotalTimeSeconds>7200</TotalTimeSeconds>
        <DistanceMeters>20000</DistanceMeters>
      </Lap>
    </Activity>
  </Activities>
</TrainingCenterDatabase>';

        // when - update workoutu z TCX (UPSERT)
        $secondResponse = $this->postJson('/api/workouts/import', [
            'source' => 'tcx',
            'sourceActivityId' => 'test-updated-alerts',
            'startTimeIso' => '2025-01-01T10:00:00Z',
            'durationSec' => 7200, // Changed duration
            'distanceM' => 20000, // Changed distance
            'rawTcxXml' => $tcxXml2,
        ]);

        $secondResponse->assertStatus(200);
        $secondResponse->assertJson([
            'id' => $workoutId,
            'created' => false,
            'updated' => true,
        ]);

        // then - alerty zostały wygenerowane/zaktualizowane
        $alerts = DB::table('training_alerts_v1')
            ->where('workout_id', $workoutId)
            ->get();

        $this->assertGreaterThan(0, $alerts->count(), 'Alerts should be generated/updated after UPDATED import');

        Carbon::setTestNow();
    }
}

