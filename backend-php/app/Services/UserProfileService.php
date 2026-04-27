<?php

namespace App\Services;

use App\Models\UserProfile;

class UserProfileService
{
    /**
     * Returns UserProfileConstraints shape matching Node backend, extended with M1 beyond minimum fields.
     * When no profile row exists, deterministic defaults are returned.
     *
     * Existing keys preserved:
     *   timezone, runningDays, surfaces, shoes, hrZones
     *
     * M1 beyond minimum additions (additive):
     *   primaryRace, availability, health, equipment, quality
     *
     * @return array{
     *   timezone:string,
     *   runningDays:array<int,string>,
     *   surfaces:array{preferTrail:bool,avoidAsphalt:bool},
     *   shoes:array{avoidZeroDrop:bool},
     *   hrZones:array{z1:array{0:int,1:int},z2:array{0:int,1:int},z3:array{0:int,1:int},z4:array{0:int,1:int},z5:array{0:int,1:int}},
     *   primaryRace:array{date:string,distanceKm:float,priority:string|null}|null,
     *   availability:array{runningDays:array<int,string>,maxSessionMin:int|null},
     *   health:array{hasCurrentPain:bool},
     *   equipment:array{hasHrSensor:bool},
     *   quality:array{score:int,hasPrimaryRace:bool,hasMaxSessionMin:bool,hasHealth:bool,hasEquipment:bool,hasHrZones:bool}
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
            // M1 beyond minimum defaults
            'primaryRace' => null,
            'availability' => ['runningDays' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'maxSessionMin' => null],
            'health' => ['hasCurrentPain' => false],
            'equipment' => ['hasHrSensor' => false],
            'paceZones' => null,
            'crossTrainingPromptPreference' => 'ask_before_plan',
            'quality' => [
                'score' => 0,
                'hasPrimaryRace' => false,
                'hasMaxSessionMin' => false,
                'hasHealth' => false,
                'hasEquipment' => false,
                'hasHrZones' => false,
            ],
        ];

        $profile = UserProfile::query()->where('user_id', $userId)->first();
        if (!$profile) {
            return $defaults;
        }

        // runningDays: prefer availability_json.runningDays (new canonical source), fallback to preferred_run_days
        $runningDays = $this->resolveRunningDays($profile, $defaults['runningDays']);

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
        $paceZones = null;
        $crossTrainingPromptPreference = $defaults['crossTrainingPromptPreference'];
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
                if (isset($parsed['paceZones']) && is_array($parsed['paceZones'])) {
                    $paceZones = $parsed['paceZones'];
                }
                if (in_array($parsed['crossTrainingPromptPreference'] ?? null, ['ask_before_plan', 'do_not_ask'], true)) {
                    $crossTrainingPromptPreference = (string) $parsed['crossTrainingPromptPreference'];
                }
            }
        }

        // HR zones columns override constraints.hrZones when present
        $hrZonesFromColumns = $this->hrZonesFromColumns($profile, $hrZones);

        // --- M1 beyond minimum ---

        // primaryRace from projection columns
        $primaryRace = null;
        if ($profile->primary_race_date !== null) {
            $selectedRace = $this->selectPrimaryRace(is_array($profile->races_json) ? $profile->races_json : []);
            $primaryRace = [
                'name' => is_array($selectedRace) ? ($selectedRace['name'] ?? null) : null,
                'date' => $profile->primary_race_date->toDateString(),
                'distanceKm' => $profile->primary_race_distance_km !== null ? (float) $profile->primary_race_distance_km : null,
                'priority' => $profile->primary_race_priority,
                'targetTime' => is_array($selectedRace) ? ($selectedRace['targetTime'] ?? null) : null,
            ];
        }

        // availability
        $avail = is_array($profile->availability_json) ? $profile->availability_json : [];
        $maxSessionMin = isset($profile->max_session_min) && is_numeric($profile->max_session_min)
            ? (int) $profile->max_session_min
            : (isset($avail['maxSessionMin']) && is_numeric($avail['maxSessionMin']) ? (int) $avail['maxSessionMin'] : null);
        $availability = [
            'runningDays' => $runningDays,
            'maxSessionMin' => $maxSessionMin,
        ];

        // health
        $hasCurrentPain = (bool) ($profile->has_current_pain ?? false);
        $health = ['hasCurrentPain' => $hasCurrentPain];

        // equipment
        $hasHrSensor = (bool) ($profile->has_hr_sensor ?? false);
        $equipment = ['hasHrSensor' => $hasHrSensor];

        // quality summary (lightweight derived from projection columns)
        $qualityScore = isset($profile->profile_quality_score) && is_numeric($profile->profile_quality_score)
            ? (int) $profile->profile_quality_score
            : 0;
        $quality = [
            'score' => $qualityScore,
            'hasPrimaryRace' => $primaryRace !== null,
            'hasMaxSessionMin' => $maxSessionMin !== null,
            'hasHealth' => is_array($profile->health_json) && array_key_exists('currentPain', $profile->health_json),
            'hasEquipment' => is_array($profile->equipment_json) && array_key_exists('hrSensor', $profile->equipment_json),
            'hasHrZones' => $this->hrZonesHaveAnyValue($profile),
        ];

        return [
            'timezone' => $defaults['timezone'],
            'runningDays' => $runningDays,
            'surfaces' => $surfaces,
            'shoes' => $shoes,
            'hrZones' => $hrZonesFromColumns,
            'primaryRace' => $primaryRace,
            'availability' => $availability,
            'health' => $health,
            'equipment' => $equipment,
            'paceZones' => $paceZones,
            'crossTrainingPromptPreference' => $crossTrainingPromptPreference,
            'quality' => $quality,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $races
     * @return array<string,mixed>|null
     */
    private function selectPrimaryRace(array $races): ?array
    {
        $today = now()->startOfDay();
        $priorityRank = ['A' => 1, 'B' => 2, 'C' => 3];
        $candidates = [];
        foreach ($races as $race) {
            if (!is_array($race) || !isset($race['date'])) {
                continue;
            }
            try {
                $dt = \Carbon\Carbon::parse((string) $race['date'])->startOfDay();
            } catch (\Throwable) {
                continue;
            }
            if ($dt->lessThan($today)) {
                continue;
            }
            $candidates[] = [
                'race' => $race,
                'dt' => $dt,
                'rank' => $priorityRank[$race['priority'] ?? ''] ?? 99,
            ];
        }
        if (empty($candidates)) {
            return null;
        }
        usort($candidates, function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }
            return $a['dt']->getTimestamp() <=> $b['dt']->getTimestamp();
        });
        return $candidates[0]['race'];
    }

    /**
     * @param array<int,string> $defaultDays
     * @return array<int,string>
     */
    private function resolveRunningDays(UserProfile $profile, array $defaultDays): array
    {
        $validDayNames = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        // Prefer new availability_json.runningDays (written by M1 beyond minimum onboarding)
        $avail = is_array($profile->availability_json) ? $profile->availability_json : [];
        if (isset($avail['runningDays']) && is_array($avail['runningDays'])) {
            $days = array_values(array_filter(
                $avail['runningDays'],
                fn ($d) => in_array($d, $validDayNames, true)
            ));
            if (count($days) > 0) {
                return $days;
            }
        }

        // Fallback: preferred_run_days (ISO number string)
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
                    return $mapped;
                }
            }
        }

        return $defaultDays;
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

    private function hrZonesHaveAnyValue(UserProfile $profile): bool
    {
        foreach (['z1', 'z2', 'z3', 'z4', 'z5'] as $z) {
            $min = $profile->{"hr_{$z}_min"};
            $max = $profile->{"hr_{$z}_max"};
            if (is_numeric($min) && is_numeric($max)) {
                return true;
            }
        }
        return false;
    }
}
