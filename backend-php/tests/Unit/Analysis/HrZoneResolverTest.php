<?php

namespace Tests\Unit\Analysis;

use App\Models\UserProfile;
use App\Services\Analysis\HrZoneResolver;
use App\Support\Analysis\Enums\HrZoneStatus;
use PHPUnit\Framework\TestCase;

class HrZoneResolverTest extends TestCase
{
    public function test_missing_when_no_profile_and_no_observed_max(): void
    {
        $result = (new HrZoneResolver)->resolve(null, null);

        $this->assertSame(HrZoneStatus::Missing, $result->status);
        $this->assertNull($result->zones);
    }

    public function test_estimated_from_observed_max_hr(): void
    {
        $result = (new HrZoneResolver)->resolve(null, 190.0);

        $this->assertSame(HrZoneStatus::Estimated, $result->status);
        $this->assertSame('max_hr_percent', $result->method);
        $this->assertNotNull($result->zones);
        $this->assertCount(5, $result->zones);
        // Z2 = 60-70% z 190 = 114-133
        $this->assertSame('Z2', $result->zones[1]['name']);
        $this->assertSame(114, $result->zones[1]['minBpm']);
        $this->assertSame(133, $result->zones[1]['maxBpm']);
    }

    public function test_known_when_profile_has_full_zones(): void
    {
        $profile = new UserProfile([
            'hr_z1_min' => 100, 'hr_z1_max' => 120,
            'hr_z2_min' => 120, 'hr_z2_max' => 140,
            'hr_z3_min' => 140, 'hr_z3_max' => 160,
            'hr_z4_min' => 160, 'hr_z4_max' => 175,
            'hr_z5_min' => 175, 'hr_z5_max' => 195,
        ]);

        $result = (new HrZoneResolver)->resolve($profile, 192.0);

        // profil ma pierwszenstwo nad obserwacja
        $this->assertSame(HrZoneStatus::Known, $result->status);
        $this->assertSame('user_provided', $result->method);
        $this->assertSame(120, $result->zones[1]['minBpm']);
        $this->assertSame(140, $result->zones[1]['maxBpm']);
    }

    public function test_falls_through_to_estimated_when_profile_zones_incomplete(): void
    {
        $profile = new UserProfile([
            'hr_z1_min' => 100, 'hr_z1_max' => 120,
            // brak Z2..Z5
        ]);

        $result = (new HrZoneResolver)->resolve($profile, 200.0);

        $this->assertSame(HrZoneStatus::Estimated, $result->status);
    }
}
