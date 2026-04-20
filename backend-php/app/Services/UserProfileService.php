<?php

namespace App\Services;

use App\Models\UserProfile;

class UserProfileService
{
    /**
     * Returns UserProfileConstraints shape matching Node backend.
     * When no profile row exists, deterministic defaults are returned.
     *
     * @return array{
     *   timezone:string,
     *   runningDays:array<int,string>,
     *   surfaces:array{preferTrail:bool,avoidAsphalt:bool},
     *   shoes:array{avoidZeroDrop:bool},
     *   hrZones:array{z1:array{0:int,1:int},z2:array{0:int,1:int},z3:array{0:int,1:int},z4:array{0:int,1:int},z5:array{0:int,1:int}}
     * }
     */
    public function getConstraintsForUser(int $userId): array
    {
        $defaults = [
            'timezone' => 'Europe/Warsaw',
            'runningDays' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            'surfaces' => ['preferTrail' => true, 'avoidAsphalt' => true],
            'shoes' => ['avoidZeroDrop' => true],
            'hrZones' => [
                'z1' => [0, 0],
                'z2' => [0, 0],
                'z3' => [0, 0],
                'z4' => [0, 0],
                'z5' => [0, 0],
            ],
        ];

        $profile = UserProfile::query()->where('user_id', $userId)->first();
        if (!$profile) {
            return $defaults;
        }

        // runningDays: JSON array of ISO day numbers (1=Mon ... 7=Sun)
        $runningDays = $defaults['runningDays'];
        if (is_string($profile->preferred_run_days) && $profile->preferred_run_days !== '') {
            $parsed = json_decode($profile->preferred_run_days, true);
            if (is_array($parsed) && count($parsed) > 0) {
                $dayMap = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
                $mapped = [];
                foreach ($parsed as $d) {
                    if (isset($dayMap[(int) $d])) {
                        $mapped[] = $dayMap[(int) $d];
                    }
                }
                if (count($mapped) > 0) {
                    $runningDays = $mapped;
                }
            }
        }

        // surfaces: preferred_surface string (e.g. TRAIL/ROAD/ASPHALT)
        $surfaces = $defaults['surfaces'];
        if (is_string($profile->preferred_surface) && $profile->preferred_surface !== '') {
            $upper = strtoupper($profile->preferred_surface);
            $surfaces = [
                'preferTrail' => str_contains($upper, 'TRAIL'),
                'avoidAsphalt' => str_contains($upper, 'ROAD') || str_contains($upper, 'ASPHALT'),
            ];
        }

        // constraints JSON may carry shoes + hrZones
        $shoes = $defaults['shoes'];
        $hrZones = $defaults['hrZones'];
        if (is_string($profile->constraints) && $profile->constraints !== '') {
            $parsed = json_decode($profile->constraints, true);
            if (is_array($parsed)) {
                if (isset($parsed['shoes']) && is_array($parsed['shoes'])) {
                    $shoes = [
                        'avoidZeroDrop' => ($parsed['shoes']['avoidZeroDrop'] ?? false) === true,
                    ];
                }
                if (isset($parsed['hrZones']) && is_array($parsed['hrZones'])) {
                    $hrZones = $this->parseHrZonesOrDefault($parsed['hrZones'], $defaults['hrZones']);
                }
            }
        }

        // HR zones columns override constraints.hrZones when present
        $hrZonesFromColumns = $this->hrZonesFromColumns($profile, $hrZones);

        return [
            'timezone' => $defaults['timezone'],
            'runningDays' => $runningDays,
            'surfaces' => $surfaces,
            'shoes' => $shoes,
            'hrZones' => $hrZonesFromColumns,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @param array{z1:array{0:int,1:int},z2:array{0:int,1:int},z3:array{0:int,1:int},z4:array{0:int,1:int},z5:array{0:int,1:int}} $fallback
     * @return array{z1:array{0:int,1:int},z2:array{0:int,1:int},z3:array{0:int,1:int},z4:array{0:int,1:int},z5:array{0:int,1:int}}
     */
    private function parseHrZonesOrDefault(array $raw, array $fallback): array
    {
        $zones = [];
        foreach (['z1', 'z2', 'z3', 'z4', 'z5'] as $zone) {
            $val = $raw[$zone] ?? null;
            if (is_array($val) && count($val) === 2 && is_numeric($val[0]) && is_numeric($val[1])) {
                $zones[$zone] = [(int) $val[0], (int) $val[1]];
            } else {
                return $fallback;
            }
        }
        return $zones;
    }

    /**
     * @param array{z1:array{0:int,1:int},z2:array{0:int,1:int},z3:array{0:int,1:int},z4:array{0:int,1:int},z5:array{0:int,1:int}} $fallback
     * @return array{z1:array{0:int,1:int},z2:array{0:int,1:int},z3:array{0:int,1:int},z4:array{0:int,1:int},z5:array{0:int,1:int}}
     */
    private function hrZonesFromColumns(UserProfile $profile, array $fallback): array
    {
        $hasAny = false;
        $zones = $fallback;
        foreach (['z1', 'z2', 'z3', 'z4', 'z5'] as $z) {
            $min = $profile->{"hr_{$z}_min"};
            $max = $profile->{"hr_{$z}_max"};
            if (is_numeric($min) && is_numeric($max)) {
                $zones[$z] = [(int) $min, (int) $max];
                $hasAny = true;
            }
        }
        return $hasAny ? $zones : $fallback;
    }
}
