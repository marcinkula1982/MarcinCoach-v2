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
     *   - distanceM         : int, sum of Lap/DistanceMeters (missing laps contribute 0)
     *   - sport             : 'run' | 'bike' | 'swim' | 'other'
     *   - hr                : ['avgBpm' => ?int, 'maxBpm' => ?int]
     *   - avgPaceSecPerKm   : ?int (only for run, distance > 0)
     *   - intensityBuckets  : ['z1Sec','z2Sec','z3Sec','z4Sec','z5Sec','totalSec']
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
     *     sport:string,
     *     hr:array{avgBpm:?int,maxBpm:?int},
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
            'distanceM' => $distanceM,
            'sport' => $sport,
            'hr' => $hrStats,
            'avgPaceSecPerKm' => $avgPaceSecPerKm,
            'intensityBuckets' => $buckets,
            'laps' => $lapCount,
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
     * @return array<int,array{time:Carbon,hr:?int}>
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

            $points[] = ['time' => $time, 'hr' => $hr];
        }

        usort($points, fn (array $a, array $b) => $a['time']->timestamp <=> $b['time']->timestamp);
        return $points;
    }

    /**
     * @param array<int,array{time:Carbon,hr:?int}> $tps
     * @return array{avgBpm:?int,maxBpm:?int}
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
            return ['avgBpm' => null, 'maxBpm' => null];
        }
        return [
            'avgBpm' => (int) round(array_sum($hrs) / count($hrs)),
            'maxBpm' => max($hrs),
        ];
    }

    /**
     * Intensity distribution resolution order:
     *   1) HR trackpoints + hrZones → time-in-zone (primary, high-fidelity).
     *   2) Running sport + avgPace → whole-duration lumped into the pace-matched zone.
     *   3) Fallback → whole duration into totalSec only (no per-zone signal).
     *
     * The resulting array is always well-formed (all six keys present, non-negative ints).
     *
     * @param array<int,array{time:Carbon,hr:?int}> $tps
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
