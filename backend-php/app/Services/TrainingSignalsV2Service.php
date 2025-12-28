<?php

namespace App\Services;

use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TrainingSignalsV2Service
{
    public function upsertForWorkout(int $workoutId): void
    {
        $workout = Workout::find($workoutId);
        if (!$workout) {
            return;
        }

        // Get raw TCX XML
        $rawTcx = DB::table('workout_raw_tcx')
            ->where('workout_id', $workoutId)
            ->first();

        if (!$rawTcx) {
            // No TCX data available
            $this->saveSignals($workoutId, false, null, null, null, null, null, null, null);
            return;
        }

        // Parse TCX XML to extract trackpoints with HR
        $trackpoints = $this->parseTcxForHeartRate($rawTcx->xml);

        if (empty($trackpoints)) {
            // No HR data available
            $this->saveSignals($workoutId, false, null, null, null, null, null, null, null);
            return;
        }

        // Filter invalid HR values (< 30 or > 230)
        $validTrackpoints = array_filter($trackpoints, function ($tp) {
            return $tp['hr'] >= 30 && $tp['hr'] <= 230;
        });

        if (empty($validTrackpoints)) {
            // No valid HR data
            $this->saveSignals($workoutId, false, null, null, null, null, null, null, null);
            return;
        }

        // Calculate HR metrics
        $hrValues = array_column($validTrackpoints, 'hr');
        $hrAvgBpm = (int) round(array_sum($hrValues) / count($hrValues));
        $hrMaxBpm = max($hrValues);
        $hrAvailable = true;

        // Get user profile with HR zones
        $profile = DB::table('user_profiles')
            ->where('user_id', $workout->user_id)
            ->first();

        $hasHrZones = $profile && $this->hasAllHrZones($profile);

        if (!$hasHrZones) {
            // No HR zones available
            $this->saveSignals($workoutId, $hrAvailable, $hrAvgBpm, $hrMaxBpm, null, null, null, null, null);
            return;
        }

        // Calculate time in each HR zone
        $zoneTimes = $this->calculateZoneTimes($validTrackpoints, $profile);

        $this->saveSignals(
            $workoutId,
            $hrAvailable,
            $hrAvgBpm,
            $hrMaxBpm,
            $zoneTimes['z1'] ?? null,
            $zoneTimes['z2'] ?? null,
            $zoneTimes['z3'] ?? null,
            $zoneTimes['z4'] ?? null,
            $zoneTimes['z5'] ?? null
        );
    }

    /**
     * Parse TCX XML to extract trackpoints with heart rate data.
     *
     * @return array Array of trackpoints with 'time' (Carbon) and 'hr' (int)
     */
    private function parseTcxForHeartRate(string $xml): array
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        
        $oldErrorReporting = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($oldErrorReporting);

        if (!$loaded || !empty($errors)) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('tcx', 'http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2');

        $trackpoints = [];
        $trackpointNodes = $xpath->query('//tcx:Trackpoint[tcx:Time and tcx:HeartRateBpm/tcx:Value]');

        foreach ($trackpointNodes as $trackpointNode) {
            // Extract Time
            $timeNodes = $xpath->query('./tcx:Time', $trackpointNode);
            if ($timeNodes->length === 0) {
                continue;
            }
            $timeStr = trim($timeNodes->item(0)->textContent);

            // Extract HeartRateBpm->Value
            $hrNodes = $xpath->query('./tcx:HeartRateBpm/tcx:Value', $trackpointNode);
            if ($hrNodes->length === 0) {
                continue;
            }
            $hrValue = (int) trim($hrNodes->item(0)->textContent);

            try {
                $time = Carbon::parse($timeStr)->utc();
                $trackpoints[] = [
                    'time' => $time,
                    'hr' => $hrValue,
                ];
            } catch (\Exception $e) {
                continue;
            }
        }

        return $trackpoints;
    }

    /**
     * Check if profile has all HR zones defined.
     */
    private function hasAllHrZones($profile): bool
    {
        $requiredFields = [
            'hr_z1_min', 'hr_z1_max',
            'hr_z2_min', 'hr_z2_max',
            'hr_z3_min', 'hr_z3_max',
            'hr_z4_min', 'hr_z4_max',
            'hr_z5_min', 'hr_z5_max',
        ];

        foreach ($requiredFields as $field) {
            if ($profile->$field === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate time spent in each HR zone.
     *
     * @param array $trackpoints Array of trackpoints with 'time' and 'hr'
     * @param object $profile User profile with HR zone definitions
     * @return array Array with keys 'z1', 'z2', 'z3', 'z4', 'z5' (values in seconds)
     */
    private function calculateZoneTimes(array $trackpoints, $profile): array
    {
        $zoneTimes = [
            'z1' => 0,
            'z2' => 0,
            'z3' => 0,
            'z4' => 0,
            'z5' => 0,
        ];

        // Sort trackpoints by time
        usort($trackpoints, function ($a, $b) {
            return $a['time']->timestamp <=> $b['time']->timestamp;
        });

        // Calculate time differences and assign to zones
        for ($i = 0; $i < count($trackpoints) - 1; $i++) {
            $current = $trackpoints[$i];
            $next = $trackpoints[$i + 1];

            // Calculate absolute time difference (next - current)
            $dt = abs($next['time']->diffInSeconds($current['time']));
            $hr = $current['hr'];

            // Assign dt to zone based on HR value
            $zone = $this->getZoneForHr($hr, $profile);
            if ($zone) {
                $zoneTimes[$zone] += $dt;
            }
        }

        return $zoneTimes;
    }

    /**
     * Determine HR zone for a given HR value.
     *
     * @param int $hr Heart rate in BPM
     * @param object $profile User profile with HR zone definitions
     * @return string|null Zone key ('z1', 'z2', 'z3', 'z4', 'z5') or null
     */
    private function getZoneForHr(int $hr, $profile): ?string
    {
        // Z1: hr_z1_min <= hr < hr_z1_max
        if ($hr >= $profile->hr_z1_min && $hr < $profile->hr_z1_max) {
            return 'z1';
        }
        // Z2: hr_z2_min <= hr < hr_z2_max
        if ($hr >= $profile->hr_z2_min && $hr < $profile->hr_z2_max) {
            return 'z2';
        }
        // Z3: hr_z3_min <= hr < hr_z3_max
        if ($hr >= $profile->hr_z3_min && $hr < $profile->hr_z3_max) {
            return 'z3';
        }
        // Z4: hr_z4_min <= hr < hr_z4_max
        if ($hr >= $profile->hr_z4_min && $hr < $profile->hr_z4_max) {
            return 'z4';
        }
        // Z5: hr_z5_min <= hr <= hr_z5_max (inclusive max for last zone)
        if ($hr >= $profile->hr_z5_min && $hr <= $profile->hr_z5_max) {
            return 'z5';
        }

        return null;
    }

    /**
     * Save signals to database.
     */
    private function saveSignals(
        int $workoutId,
        bool $hrAvailable,
        ?int $hrAvgBpm,
        ?int $hrMaxBpm,
        ?int $hrZ1Sec,
        ?int $hrZ2Sec,
        ?int $hrZ3Sec,
        ?int $hrZ4Sec,
        ?int $hrZ5Sec
    ): void {
        DB::table('training_signals_v2')->updateOrInsert(
            ['workout_id' => $workoutId],
            [
                'hr_available' => $hrAvailable,
                'hr_avg_bpm' => $hrAvgBpm,
                'hr_max_bpm' => $hrMaxBpm,
                'hr_z1_sec' => $hrZ1Sec,
                'hr_z2_sec' => $hrZ2Sec,
                'hr_z3_sec' => $hrZ3Sec,
                'hr_z4_sec' => $hrZ4Sec,
                'hr_z5_sec' => $hrZ5Sec,
                'generated_at' => now(),
            ]
        );
    }
}

