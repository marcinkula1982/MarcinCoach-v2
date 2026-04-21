<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Deterministic, pure scoring of UserProfile completeness and quality.
 * No DB access — operates on already-hydrated profile arrays or scalar values.
 *
 * Score breakdown (max 100):
 *   +15  runningDays  — at least one valid day declared
 *   +20  primaryRace  — at least one future race in races_json (projection columns)
 *   +15  maxSessionMin — declared and in valid range (15–300)
 *   +10  health       — injuryHistory array + currentPain boolean explicitly set
 *   +10  equipment    — watch + hrSensor explicitly set
 *   +20  hrZones      — all 5 pairs filled, monotonically ascending boundaries
 *   +10  surface      — preferred_surface explicitly set
 */
class ProfileQualityScoreService
{
    private const POINTS_RUNNING_DAYS = 15;
    private const POINTS_PRIMARY_RACE = 20;
    private const POINTS_MAX_SESSION = 15;
    private const POINTS_HEALTH = 10;
    private const POINTS_EQUIPMENT = 10;
    private const POINTS_HR_ZONES = 20;
    private const POINTS_SURFACE = 10;

    private const DAY_NAMES = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param array{
     *   preferred_run_days?: string|null,
     *   preferred_surface?: string|null,
     *   races_json?: array<int,array<string,mixed>>|null,
     *   availability_json?: array<string,mixed>|null,
     *   health_json?: array<string,mixed>|null,
     *   equipment_json?: array<string,mixed>|null,
     *   hr_z1_min?: int|null, hr_z1_max?: int|null,
     *   hr_z2_min?: int|null, hr_z2_max?: int|null,
     *   hr_z3_min?: int|null, hr_z3_max?: int|null,
     *   hr_z4_min?: int|null, hr_z4_max?: int|null,
     *   hr_z5_min?: int|null, hr_z5_max?: int|null,
     *   max_session_min?: int|null,
     *   has_current_pain?: bool|null,
     *   has_hr_sensor?: bool|null,
     *   primary_race_date?: string|\DateTimeInterface|null,
     * } $profileData
     * @return array{score:int,breakdown:array<string,array{points:int,max:int,ok:bool}>}
     */
    public function scoreWithBreakdown(array $profileData): array
    {
        $breakdown = [];

        // runningDays
        $runningDays = $this->resolveRunningDays($profileData);
        $hasRunningDays = count($runningDays) >= 1;
        $breakdown['runningDays'] = [
            'points' => $hasRunningDays ? self::POINTS_RUNNING_DAYS : 0,
            'max' => self::POINTS_RUNNING_DAYS,
            'ok' => $hasRunningDays,
        ];

        // primaryRace
        $hasPrimaryRace = $this->hasFutureRace($profileData);
        $breakdown['primaryRace'] = [
            'points' => $hasPrimaryRace ? self::POINTS_PRIMARY_RACE : 0,
            'max' => self::POINTS_PRIMARY_RACE,
            'ok' => $hasPrimaryRace,
        ];

        // maxSessionMin
        $maxSession = isset($profileData['max_session_min']) && is_numeric($profileData['max_session_min'])
            ? (int) $profileData['max_session_min']
            : null;
        // also check availability_json as fallback (when projection not yet written)
        if ($maxSession === null) {
            $avail = $profileData['availability_json'] ?? null;
            if (is_array($avail) && isset($avail['maxSessionMin']) && is_numeric($avail['maxSessionMin'])) {
                $maxSession = (int) $avail['maxSessionMin'];
            }
        }
        $hasMaxSession = $maxSession !== null && $maxSession >= 15 && $maxSession <= 300;
        $breakdown['maxSessionMin'] = [
            'points' => $hasMaxSession ? self::POINTS_MAX_SESSION : 0,
            'max' => self::POINTS_MAX_SESSION,
            'ok' => $hasMaxSession,
        ];

        // health
        $health = $profileData['health_json'] ?? null;
        $hasHealth = is_array($health)
            && array_key_exists('injuryHistory', $health) && is_array($health['injuryHistory'])
            && array_key_exists('currentPain', $health) && is_bool($health['currentPain']);
        $breakdown['health'] = [
            'points' => $hasHealth ? self::POINTS_HEALTH : 0,
            'max' => self::POINTS_HEALTH,
            'ok' => $hasHealth,
        ];

        // equipment
        $equipment = $profileData['equipment_json'] ?? null;
        $hasEquipment = is_array($equipment)
            && array_key_exists('watch', $equipment) && is_bool($equipment['watch'])
            && array_key_exists('hrSensor', $equipment) && is_bool($equipment['hrSensor']);
        $breakdown['equipment'] = [
            'points' => $hasEquipment ? self::POINTS_EQUIPMENT : 0,
            'max' => self::POINTS_EQUIPMENT,
            'ok' => $hasEquipment,
        ];

        // hrZones
        $hasHrZones = $this->hrZonesCompleteAndConsistent($profileData);
        $breakdown['hrZones'] = [
            'points' => $hasHrZones ? self::POINTS_HR_ZONES : 0,
            'max' => self::POINTS_HR_ZONES,
            'ok' => $hasHrZones,
        ];

        // surface
        $surface = $profileData['preferred_surface'] ?? null;
        $hasSurface = is_string($surface) && $surface !== '';
        $breakdown['surface'] = [
            'points' => $hasSurface ? self::POINTS_SURFACE : 0,
            'max' => self::POINTS_SURFACE,
            'ok' => $hasSurface,
        ];

        $score = array_sum(array_column($breakdown, 'points'));

        return [
            'score' => (int) $score,
            'breakdown' => $breakdown,
        ];
    }

    public function score(array $profileData): int
    {
        return $this->scoreWithBreakdown($profileData)['score'];
    }

    /**
     * @param array<string,mixed> $profileData
     * @return array<int,string>
     */
    private function resolveRunningDays(array $profileData): array
    {
        // Prefer new availability_json.runningDays
        $avail = $profileData['availability_json'] ?? null;
        if (is_array($avail) && isset($avail['runningDays']) && is_array($avail['runningDays'])) {
            $days = array_filter($avail['runningDays'], fn ($d) => in_array($d, self::DAY_NAMES, true));
            if (count($days) >= 1) {
                return array_values($days);
            }
        }

        // Fallback: preferred_run_days JSON string with ISO numbers
        $prd = $profileData['preferred_run_days'] ?? null;
        if (is_string($prd) && $prd !== '') {
            $parsed = json_decode($prd, true);
            $dayMap = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
            if (is_array($parsed)) {
                $mapped = array_filter(array_map(fn ($d) => $dayMap[(int) $d] ?? null, $parsed));
                if (count($mapped) >= 1) {
                    return array_values($mapped);
                }
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $profileData
     */
    private function hasFutureRace(array $profileData): bool
    {
        // Check projection column first
        $primaryDate = $profileData['primary_race_date'] ?? null;
        if ($primaryDate !== null) {
            try {
                $dt = $primaryDate instanceof \DateTimeInterface
                    ? Carbon::instance($primaryDate)
                    : Carbon::parse((string) $primaryDate);
                if ($dt->isFuture()) {
                    return true;
                }
            } catch (\Throwable) {
                // fall through to races_json
            }
        }

        // Check races_json for any future race
        $races = $profileData['races_json'] ?? null;
        if (!is_array($races)) {
            return false;
        }
        $today = Carbon::today();
        foreach ($races as $race) {
            if (!is_array($race) || !isset($race['date'])) {
                continue;
            }
            try {
                $raceDt = Carbon::parse((string) $race['date']);
                if ($raceDt->greaterThanOrEqualTo($today)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $profileData
     */
    private function hrZonesCompleteAndConsistent(array $profileData): bool
    {
        $zones = [];
        foreach (['z1', 'z2', 'z3', 'z4', 'z5'] as $z) {
            $min = $profileData["hr_{$z}_min"] ?? null;
            $max = $profileData["hr_{$z}_max"] ?? null;
            if (!is_numeric($min) || !is_numeric($max)) {
                return false;
            }
            $zones[$z] = [(int) $min, (int) $max];
        }

        // Each zone: min < max
        foreach ($zones as [$min, $max]) {
            if ($min >= $max) {
                return false;
            }
        }

        // Monotonically ascending: z_n.max <= z_{n+1}.min
        $order = ['z1', 'z2', 'z3', 'z4', 'z5'];
        for ($i = 0; $i < 4; $i++) {
            if ($zones[$order[$i]][1] > $zones[$order[$i + 1]][0]) {
                return false;
            }
        }

        return true;
    }
}
