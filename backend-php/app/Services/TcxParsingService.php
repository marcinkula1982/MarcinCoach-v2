<?php

namespace App\Services;

use Carbon\Carbon;

class TcxParsingService
{
    /**
     * Parse a TCX XML document into a canonical shape.
     *
     * Contract (used by the import pipeline and the analytics layer):
     *   - startTimeIso      : ISO-8601 Z (from Activity/Id)
     *   - durationSec       : int, sum of Lap/TotalTimeSeconds
     *   - movingTimeSec     : int, best-effort moving time from trackpoints
     *   - elapsedTimeSec    : int, elapsed time from trackpoints/laps
     *   - distanceM         : int, sum of Lap/DistanceMeters (missing laps contribute 0)
     *   - sport             : 'run' | 'bike' | 'swim' | 'other'
     *   - hr                : ['avgBpm' => ?int, 'maxBpm' => ?int]
     *   - avgPaceSecPerKm   : ?int (only for run, distance > 0)
     *   - intensityBuckets  : ['z1Sec','z2Sec','z3Sec','z4Sec','z5Sec','totalSec']
     *   - cadence           : ['avgSpm' => ?int, 'maxSpm' => ?int]
     *   - power             : ['avgWatts' => ?int, 'maxWatts' => ?int]
     *   - elevationGainMeters / elevationLossMeters
     *   - paceZones / dataAvailability
     *   - laps              : int
     *
     * Throws \InvalidArgumentException for hard-invalid TCX:
     *   - malformed XML
     *   - missing Activity/Id
     *   - missing Lap elements
     *   - missing TotalTimeSeconds in any Lap
     * DistanceMeters is optional per-lap (relaxed for pool swims / treadmill intervals).
     *
     * @param array<string,array{min:int,max:int}>|null $hrZones
     *        e.g. ['z1' => ['min'=>50,'max'=>100], ...]. When null or incomplete,
     *        HR-based zone time is skipped (parser falls back to pace-based or
     *        lumped buckets).
     *
     * @return array{
     *     startTimeIso:string,
     *     durationSec:int,
     *     distanceM:int,
     *     elapsedTimeSec:int,
     *     movingTimeSec:int,
     *     sport:string,
     *     hr:array{avgBpm:?int,maxBpm:?int},
     *     hrSampleCount:int,
     *     avgPaceSecPerKm:?int,
     *     intensityBuckets:array{z1Sec:int,z2Sec:int,z3Sec:int,z4Sec:int,z5Sec:int,totalSec:int},
     *     laps:int
     * }
     */
    public function parse(string $xml, ?array $hrZones = null): array
    {
        $dom = $this->loadDom($xml);
        $xpath = $this->makeXpath($dom);

        $idNodes = $xpath->query('//tcx:Activity/tcx:Id');
        if ($idNodes->length === 0) {
            throw new \InvalidArgumentException('Missing Activity/Id in TCX');
        }
        $startTimeIsoRaw = trim($idNodes->item(0)->textContent);
        try {
            $dt = new \DateTime($startTimeIsoRaw, new \DateTimeZone('UTC'));
            $startTimeIso = $dt->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            throw new \InvalidArgumentException('Invalid date format in Activity/Id: ' . $startTimeIsoRaw);
        }

        $activityNodes = $xpath->query('//tcx:Activity');
        $sportRaw = $activityNodes->length > 0 ? trim((string) $activityNodes->item(0)->getAttribute('Sport')) : '';
        $sport = $this->mapSport($sportRaw);

        $lapNodes = $xpath->query('//tcx:Lap');
        if ($lapNodes->length === 0) {
            throw new \InvalidArgumentException('No Lap elements found in TCX');
        }

        $totalDurationSec = 0.0;
        $totalDistanceM = 0.0;
        $lapCount = 0;
        foreach ($lapNodes as $lap) {
            $lapCount++;
            $timeNodes = $xpath->query('./tcx:TotalTimeSeconds', $lap);
            if ($timeNodes->length === 0) {
                throw new \InvalidArgumentException('Missing TotalTimeSeconds in TCX Lap');
            }
            $totalDurationSec += (float) trim($timeNodes->item(0)->textContent);

            $distanceNodes = $xpath->query('./tcx:DistanceMeters', $lap);
            if ($distanceNodes->length > 0) {
                $totalDistanceM += (float) trim($distanceNodes->item(0)->textContent);
            }
        }

        $durationSec = (int) round($totalDurationSec);
        $distanceM = (int) round($totalDistanceM);

        $trackpoints = $this->extractTrackpoints($xpath, requireHr: false);
        $hrStats = $this->computeHrStats($trackpoints);
        $cadenceStats = $this->computeStats($trackpoints, 'cadence', 'avgSpm', 'maxSpm');
        $powerStats = $this->computeStats($trackpoints, 'power', 'avgWatts', 'maxWatts');
        $elevation = $this->computeElevation($trackpoints);
        $movingTimeSec = $this->computeMovingTime($trackpoints, $durationSec);
        $elapsedTimeSec = $this->computeElapsedTime($trackpoints, $durationSec);

        $avgPaceSecPerKm = null;
        if ($sport === 'run' && $distanceM > 0 && $durationSec > 0) {
            $avgPaceSecPerKm = (int) round($durationSec / ($distanceM / 1000));
        }

        $buckets = $this->computeIntensityBuckets(
            $trackpoints,
            $hrZones,
            $durationSec,
            $sport,
            $avgPaceSecPerKm,
        );

        return [
            'startTimeIso' => $startTimeIso,
            'durationSec' => $durationSec,
            'elapsedTimeSec' => $elapsedTimeSec,
            'movingTimeSec' => $movingTimeSec,
            'distanceM' => $distanceM,
            'sport' => $sport,
            'hr' => $hrStats,
            'hrSampleCount' => $hrStats['sampleCount'],
            'avgPaceSecPerKm' => $avgPaceSecPerKm,
            'intensityBuckets' => $buckets,
            'elevationGainMeters' => $elevation['gain'],
            'elevationLossMeters' => $elevation['loss'],
            'cadence' => $cadenceStats,
            'power' => $powerStats,
            'paceZones' => $buckets + ['status' => $avgPaceSecPerKm === null ? 'missing' : 'estimated'],
            'dataAvailability' => [
                'gps' => $this->hasDistanceTrackpoints($trackpoints),
                'hr' => $hrStats['sampleCount'] > 0,
                'cadence' => $cadenceStats['avgSpm'] !== null,
                'power' => $powerStats['avgWatts'] !== null,
                'elevation' => $elevation['gain'] > 0 || $elevation['loss'] > 0,
                'movingTime' => $movingTimeSec > 0,
            ],
            'laps' => $lapCount,
            'fileType' => 'tcx',
        ];
    }

    /**
     * Returns sorted trackpoints with valid HR (30..230 bpm).
     * Returns [] for malformed XML instead of throwing — callers can
     * degrade gracefully (used by TrainingSignalsV2Service).
     *
     * @return array<int,array{time:Carbon,hr:int}>
     */
    public function parseHeartRateTrackpoints(string $xml): array
    {
        try {
            $dom = $this->loadDom($xml);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $xpath = $this->makeXpath($dom);
        $raw = $this->extractTrackpoints($xpath, requireHr: true);
        $out = [];
        foreach ($raw as $tp) {
            if ($tp['hr'] === null) {
                continue;
            }
            $out[] = ['time' => $tp['time'], 'hr' => (int) $tp['hr']];
        }
        return $out;
    }

    private function loadDom(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $old = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        if (!$loaded || !empty($errors)) {
            throw new \InvalidArgumentException('Invalid TCX XML format');
        }
        return $dom;
    }

    private function makeXpath(\DOMDocument $dom): \DOMXPath
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('tcx', 'http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2');
        return $xpath;
    }

    private function mapSport(string $raw): string
    {
        $lower = strtolower($raw);
        if ($lower === '') {
            return 'other';
        }
        if (str_contains($lower, 'run')) {
            return 'run';
        }
        if (str_contains($lower, 'bik') || str_contains($lower, 'cycl')) {
            return 'bike';
        }
        if (str_contains($lower, 'swim')) {
            return 'swim';
        }
        return 'other';
    }

    /**
     * @return array<int,array{time:Carbon,hr:?int,distanceM:?float,altitudeM:?float,cadence:?int,power:?int}>
     */
    private function extractTrackpoints(\DOMXPath $xpath, bool $requireHr): array
    {
        $query = $requireHr
            ? '//tcx:Trackpoint[tcx:Time and tcx:HeartRateBpm/tcx:Value]'
            : '//tcx:Trackpoint[tcx:Time]';
        $nodes = $xpath->query($query);

        $points = [];
        foreach ($nodes as $tp) {
            $timeNodes = $xpath->query('./tcx:Time', $tp);
            if ($timeNodes->length === 0) {
                continue;
            }
            try {
                $time = Carbon::parse(trim($timeNodes->item(0)->textContent))->utc();
            } catch (\Exception) {
                continue;
            }

            $hr = null;
            $hrNodes = $xpath->query('./tcx:HeartRateBpm/tcx:Value', $tp);
            if ($hrNodes->length > 0) {
                $hrVal = (int) trim($hrNodes->item(0)->textContent);
                if ($hrVal >= 30 && $hrVal <= 230) {
                    $hr = $hrVal;
                }
            }

            if ($requireHr && $hr === null) {
                continue;
            }

            $distance = null;
            $distanceNodes = $xpath->query('./tcx:DistanceMeters', $tp);
            if ($distanceNodes->length > 0 && is_numeric(trim($distanceNodes->item(0)->textContent))) {
                $distance = (float) trim($distanceNodes->item(0)->textContent);
            }

            $altitude = null;
            $altitudeNodes = $xpath->query('./tcx:AltitudeMeters', $tp);
            if ($altitudeNodes->length > 0 && is_numeric(trim($altitudeNodes->item(0)->textContent))) {
                $altitude = (float) trim($altitudeNodes->item(0)->textContent);
            }

            $cadence = null;
            $cadenceNodes = $xpath->query('./tcx:Cadence', $tp);
            if ($cadenceNodes->length > 0 && is_numeric(trim($cadenceNodes->item(0)->textContent))) {
                $cadence = (int) trim($cadenceNodes->item(0)->textContent);
            }

            $power = null;
            $powerNodes = $xpath->query('.//*[local-name()="Watts" or local-name()="PowerInWatts"]', $tp);
            if ($powerNodes->length > 0 && is_numeric(trim($powerNodes->item(0)->textContent))) {
                $power = (int) trim($powerNodes->item(0)->textContent);
            }

            $points[] = [
                'time' => $time,
                'hr' => $hr,
                'distanceM' => $distance,
                'altitudeM' => $altitude,
                'cadence' => $cadence !== null && $cadence > 0 ? $cadence : null,
                'power' => $power !== null && $power > 0 ? $power : null,
            ];
        }

        usort($points, fn (array $a, array $b) => $a['time']->timestamp <=> $b['time']->timestamp);
        return $points;
    }

    /**
     * @param array<int,array{time:Carbon,hr:?int,distanceM:?float,altitudeM:?float,cadence:?int,power:?int}> $tps
     * @return array{avgBpm:?int,maxBpm:?int,sampleCount:int}
     */
    private function computeHrStats(array $tps): array
    {
        $hrs = [];
        foreach ($tps as $tp) {
            if ($tp['hr'] !== null) {
                $hrs[] = (int) $tp['hr'];
            }
        }
        if (empty($hrs)) {
            return ['avgBpm' => null, 'maxBpm' => null, 'sampleCount' => 0];
        }
        return [
            'avgBpm' => (int) round(array_sum($hrs) / count($hrs)),
            'maxBpm' => max($hrs),
            'sampleCount' => count($hrs),
        ];
    }

    /**
     * @param array<int,array{time:Carbon,hr:?int,distanceM:?float,altitudeM:?float,cadence:?int,power:?int}> $tps
     * @return array<string,int|null>
     */
    private function computeStats(array $tps, string $key, string $avgKey, string $maxKey): array
    {
        $values = [];
        foreach ($tps as $tp) {
            if (isset($tp[$key]) && is_numeric($tp[$key]) && (float) $tp[$key] > 0) {
                $values[] = (int) $tp[$key];
            }
        }
        if (empty($values)) {
            return [$avgKey => null, $maxKey => null];
        }
        return [$avgKey => (int) round(array_sum($values) / count($values)), $maxKey => max($values)];
    }

    /**
     * @param array<int,array{time:Carbon,hr:?int,distanceM:?float,altitudeM:?float,cadence:?int,power:?int}> $tps
     * @return array{gain:float,loss:float}
     */
    private function computeElevation(array $tps): array
    {
        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i < count($tps); $i++) {
            if ($tps[$i - 1]['altitudeM'] === null || $tps[$i]['altitudeM'] === null) {
                continue;
            }
            $delta = (float) $tps[$i]['altitudeM'] - (float) $tps[$i - 1]['altitudeM'];
            if ($delta > 0) {
                $gain += $delta;
            } elseif ($delta < 0) {
                $loss += abs($delta);
            }
        }
        return ['gain' => round($gain, 2), 'loss' => round($loss, 2)];
    }

    /**
     * @param array<int,array{time:Carbon,hr:?int,distanceM:?float,altitudeM:?float,cadence:?int,power:?int}> $tps
     */
    private function computeMovingTime(array $tps, int $fallbackDurationSec): int
    {
        if (count($tps) < 2) {
            return $fallbackDurationSec;
        }
        $moving = 0;
        for ($i = 1; $i < count($tps); $i++) {
            $dt = (int) abs($tps[$i]['time']->diffInSeconds($tps[$i - 1]['time']));
            if ($dt <= 0) {
                continue;
            }
            $prevDistance = $tps[$i - 1]['distanceM'];
            $curDistance = $tps[$i]['distanceM'];
            if ($prevDistance === null || $curDistance === null) {
                $moving += $dt;
                continue;
            }
            $delta = max(0.0, (float) $curDistance - (float) $prevDistance);
            if ($delta / $dt >= 0.5) {
                $moving += $dt;
            }
        }
        return $moving > 0 ? $moving : $fallbackDurationSec;
    }

    /**
     * @param array<int,array{time:Carbon,hr:?int,distanceM:?float,altitudeM:?float,cadence:?int,power:?int}> $tps
     */
    private function computeElapsedTime(array $tps, int $fallbackDurationSec): int
    {
        if (count($tps) < 2) {
            return $fallbackDurationSec;
        }
        return max(1, (int) abs($tps[0]['time']->diffInSeconds($tps[count($tps) - 1]['time'])));
    }

    /**
     * @param array<int,array{time:Carbon,hr:?int,distanceM:?float,altitudeM:?float,cadence:?int,power:?int}> $tps
     */
    private function hasDistanceTrackpoints(array $tps): bool
    {
        foreach ($tps as $tp) {
            if ($tp['distanceM'] !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Intensity distribution resolution order:
     *   1) HR trackpoints + hrZones → time-in-zone (primary, high-fidelity).
     *   2) Running sport + avgPace → whole-duration lumped into the pace-matched zone.
     *   3) Fallback → whole duration into totalSec only (no per-zone signal).
     *
     * The resulting array is always well-formed (all six keys present, non-negative ints).
     *
     * @param array<int,array{time:Carbon,hr:?int,distanceM:?float,altitudeM:?float,cadence:?int,power:?int}> $tps
     * @param array<string,array{min:int,max:int}>|null $hrZones
     * @return array{z1Sec:int,z2Sec:int,z3Sec:int,z4Sec:int,z5Sec:int,totalSec:int}
     */
    private function computeIntensityBuckets(
        array $tps,
        ?array $hrZones,
        int $durationSec,
        string $sport,
        ?int $avgPaceSecPerKm,
    ): array {
        $zone = ['z1Sec' => 0, 'z2Sec' => 0, 'z3Sec' => 0, 'z4Sec' => 0, 'z5Sec' => 0];

        // (1) HR-based time-in-zone.
        if ($this->hasCompleteZones($hrZones) && count($tps) >= 2) {
            $totalFromZones = 0;
            $count = count($tps);
            for ($i = 0; $i < $count - 1; $i++) {
                $cur = $tps[$i];
                $nxt = $tps[$i + 1];
                if ($cur['hr'] === null) {
                    continue;
                }
                $dt = (int) abs($nxt['time']->diffInSeconds($cur['time']));
                if ($dt <= 0) {
                    continue;
                }
                $z = $this->zoneForHr((int) $cur['hr'], $hrZones);
                if ($z !== null) {
                    $zone["z{$z}Sec"] += $dt;
                    $totalFromZones += $dt;
                }
            }
            if ($totalFromZones > 0) {
                return $zone + ['totalSec' => $totalFromZones];
            }
        }

        // (2) Pace-based bucket for run (single-zone lump).
        if ($sport === 'run' && $avgPaceSecPerKm !== null && $durationSec > 0) {
            $zoneKey = match (true) {
                $avgPaceSecPerKm >= 360 => 'z1Sec',  // >= 6:00/km
                $avgPaceSecPerKm >= 300 => 'z2Sec',  // 5:00-5:59/km
                $avgPaceSecPerKm >= 255 => 'z3Sec',  // 4:15-4:59/km
                $avgPaceSecPerKm >= 225 => 'z4Sec',  // 3:45-4:14/km
                default                 => 'z5Sec',
            };
            $zone[$zoneKey] = $durationSec;
            return $zone + ['totalSec' => $durationSec];
        }

        // (3) Generic fallback: total only.
        if ($durationSec > 0) {
            return $zone + ['totalSec' => $durationSec];
        }

        return $zone + ['totalSec' => 0];
    }

    private function hasCompleteZones(?array $hrZones): bool
    {
        if ($hrZones === null) {
            return false;
        }
        for ($i = 1; $i <= 5; $i++) {
            $key = "z{$i}";
            if (!isset($hrZones[$key]['min'], $hrZones[$key]['max'])) {
                return false;
            }
        }
        return true;
    }

    private function zoneForHr(int $hr, array $hrZones): ?int
    {
        // Zones are half-open [min, max) except the last which is closed, matching the
        // legacy TrainingSignalsV2Service behavior so compliance v2 stays deterministic.
        for ($i = 1; $i <= 5; $i++) {
            $min = (int) $hrZones["z{$i}"]['min'];
            $max = (int) $hrZones["z{$i}"]['max'];
            if ($i < 5 && $hr >= $min && $hr < $max) {
                return $i;
            }
            if ($i === 5 && $hr >= $min && $hr <= $max) {
                return $i;
            }
        }
        return null;
    }
}
