<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\UserProfileService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserProfileService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new UserProfileService();
        User::create([
            'id' => 1,
            'name' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    // --- Default values when no profile row ---

    public function test_no_profile_returns_safe_defaults(): void
    {
        $result = $this->svc->getConstraintsForUser(999);

        $this->assertSame('Europe/Warsaw', $result['timezone']);
        $this->assertNotEmpty($result['runningDays']);
        $this->assertNull($result['primaryRace']);
        $this->assertFalse($result['health']['hasCurrentPain']);
        $this->assertFalse($result['equipment']['hasHrSensor']);
        $this->assertSame(0, $result['quality']['score']);
    }

    // --- runningDays priority: availability_json over preferred_run_days ---

    public function test_running_days_prefers_availability_json(): void
    {
        $profile = UserProfile::create([
            'user_id' => 1,
            'preferred_run_days' => '[1,2,3,4,5,6,7]', // all days
            'availability_json' => ['runningDays' => ['mon', 'wed', 'fri']],
        ]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertSame(['mon', 'wed', 'fri'], $result['runningDays']);
        $this->assertSame(['mon', 'wed', 'fri'], $result['availability']['runningDays']);
    }

    public function test_running_days_falls_back_to_preferred_run_days(): void
    {
        UserProfile::create([
            'user_id' => 1,
            'preferred_run_days' => '[1,3,5]',
        ]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertSame(['mon', 'wed', 'fri'], $result['runningDays']);
    }

    public function test_running_days_uses_default_when_both_empty(): void
    {
        UserProfile::create(['user_id' => 1]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertCount(7, $result['runningDays']);
    }

    // --- primaryRace from projection columns ---

    public function test_primary_race_returned_from_projection_columns(): void
    {
        UserProfile::create([
            'user_id' => 1,
            'primary_race_date' => Carbon::now()->addMonths(2)->toDateString(),
            'primary_race_distance_km' => 21.10,
            'primary_race_priority' => 'A',
        ]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertNotNull($result['primaryRace']);
        $this->assertSame('A', $result['primaryRace']['priority']);
        $this->assertSame(21.10, $result['primaryRace']['distanceKm']);
    }

    public function test_primary_race_null_when_not_set(): void
    {
        UserProfile::create(['user_id' => 1]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertNull($result['primaryRace']);
        $this->assertFalse($result['quality']['hasPrimaryRace']);
    }

    // --- maxSessionMin ---

    public function test_max_session_min_from_projection_column(): void
    {
        UserProfile::create([
            'user_id' => 1,
            'max_session_min' => 60,
        ]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertSame(60, $result['availability']['maxSessionMin']);
        $this->assertTrue($result['quality']['hasMaxSessionMin']);
    }

    public function test_max_session_min_from_availability_json_fallback(): void
    {
        UserProfile::create([
            'user_id' => 1,
            'availability_json' => ['runningDays' => ['mon'], 'maxSessionMin' => 75],
        ]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertSame(75, $result['availability']['maxSessionMin']);
    }

    public function test_max_session_min_null_when_not_set(): void
    {
        UserProfile::create(['user_id' => 1]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertNull($result['availability']['maxSessionMin']);
        $this->assertFalse($result['quality']['hasMaxSessionMin']);
    }

    // --- health / hasCurrentPain ---

    public function test_has_current_pain_true_when_column_set(): void
    {
        UserProfile::create([
            'user_id' => 1,
            'has_current_pain' => true,
            'health_json' => ['injuryHistory' => [], 'currentPain' => true],
        ]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertTrue($result['health']['hasCurrentPain']);
    }

    public function test_has_current_pain_false_by_default(): void
    {
        UserProfile::create(['user_id' => 1]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertFalse($result['health']['hasCurrentPain']);
    }

    // --- equipment / hasHrSensor ---

    public function test_has_hr_sensor_true_when_column_set(): void
    {
        UserProfile::create([
            'user_id' => 1,
            'has_hr_sensor' => true,
            'equipment_json' => ['watch' => true, 'hrSensor' => true],
        ]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertTrue($result['equipment']['hasHrSensor']);
        $this->assertTrue($result['quality']['hasEquipment']);
    }

    // --- quality shape ---

    public function test_quality_shape_has_expected_keys(): void
    {
        UserProfile::create(['user_id' => 1]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertArrayHasKey('score', $result['quality']);
        $this->assertArrayHasKey('hasPrimaryRace', $result['quality']);
        $this->assertArrayHasKey('hasMaxSessionMin', $result['quality']);
        $this->assertArrayHasKey('hasHealth', $result['quality']);
        $this->assertArrayHasKey('hasEquipment', $result['quality']);
        $this->assertArrayHasKey('hasHrZones', $result['quality']);
    }

    public function test_quality_score_loaded_from_projection_column(): void
    {
        UserProfile::create([
            'user_id' => 1,
            'profile_quality_score' => 75,
        ]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertSame(75, $result['quality']['score']);
    }

    // --- additive shape: existing keys still present ---

    public function test_existing_keys_still_present_in_result(): void
    {
        UserProfile::create(['user_id' => 1]);

        $result = $this->svc->getConstraintsForUser(1);

        $this->assertArrayHasKey('timezone', $result);
        $this->assertArrayHasKey('runningDays', $result);
        $this->assertArrayHasKey('surfaces', $result);
        $this->assertArrayHasKey('shoes', $result);
        $this->assertArrayHasKey('hrZones', $result);
    }
}
