<?php

namespace App\Services;

use Carbon\Carbon;

class GpxParsingService
{
    /**
     * @return array<string,mixed>
     */
    public function parse(string $xml): array
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $old = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        if (!$loaded || !empty($errors)) {
            throw new \InvalidArgumentException('Invalid GPX XML format');
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('gpx', 'http://www.topografix.com/GPX/1/1');
        $xpath->registerNamespace('gpxtpx', 'http://www.garmin.com/xmlschemas/TrackPointExtension/v1');

        $nodes = $xpath->query('//*[local-name()="trkpt" or local-name()="rtept"]');
        if ($nodes->length === 0) {
            throw new \InvalidArgumentException('No GPX trackpoints found');
        }

        $points = [];
        foreach ($nodes as $node) {
            $lat = is_numeric($node->attributes?->getNamedItem('lat')?->nodeValue ?? null)
                ? (float) $node->attributes->getNamedItem('lat')->nodeValue
                : null;
            $lon = is_numeric($node->attributes?->getNamedItem('lon')?->nodeValue ?? null)
                ? (float) $node->attributes->getNamedItem('lon')->nodeValue
                : null;
            if ($lat === null || $lon === null) {
                continue;
            }

            $time = $this->firstText($xpath, './*[local-name()="time"]', $node);
            if ($time === null) {
                continue;
            }

            try {
                $at = Carbon::parse($time)->utc();
            } catch (\Throwable) {
                continue;
            }

            $points[] = [
                'time' => $at,
                'lat' => $lat,
                'lon' => $lon,
                'ele' => $this->floatOrNull($this->firstText($xpath, './*[local-name()="ele"]', $node)),
                'hr' => $this->intOrNull($this->firstText($xpath, './/*[local-name()="hr"]', $node)),
                'cadence' => $this->intOrNull($this->firstText($xpath, './/*[local-name()="cad"]', $node)),
                'power' => $this->intOrNull($this->firstText($xpath, './/*[local-name()="power" or local-name()="PowerInWatts"]', $node)),
            ];
        }

        usort($points, fn (array $a, array $b) => $a['time']->timestamp <=> $b['time']->timestamp);
        if (count($points) < 2) {
            throw new \InvalidArgumentException('GPX needs at least two timed trackpoints');
        }

        $distanceM = 0.0;
        $movingTimeSec = 0;
        $elapsedTimeSec = max(1, (int) abs($points[0]['time']->diffInSeconds($points[count($points) - 1]['time'])));
        $gain = 0.0;
        $loss = 0.0;

        for ($i = 1; $i < count($points); $i++) {
            $prev = $points[$i - 1];
            $cur = $points[$i];
            $segmentM = $this->haversineMeters($prev['lat'], $prev['lon'], $cur['lat'], $cur['lon']);
            $distanceM += $segmentM;
            $dt = (int) abs($cur['time']->diffInSeconds($prev['time']));
            if ($dt > 0 && $segmentM / $dt >= 0.5) {
                $movingTimeSec += $dt;
            }

            if ($prev['ele'] !== null && $cur['ele'] !== null) {
                $delta = (float) $cur['ele'] - (float) $prev['ele'];
                if ($delta > 0) {
                    $gain += $delta;
                } elseif ($delta < 0) {
                    $loss += abs($delta);
                }
            }
        }

        if ($movingTimeSec <= 0) {
            $movingTimeSec = $elapsedTimeSec;
        }

        $durationSec = $elapsedTimeSec;
        $distanceRounded = (int) round($distanceM);
        $avgPace = $distanceRounded > 0 ? (int) round($movingTimeSec / ($distanceRounded / 1000.0)) : null;
        $hrValues = array_values(array_filter(array_column($points, 'hr'), fn ($v) => is_numeric($v) && $v >= 30 && $v <= 230));
        $cadValues = array_values(array_filter(array_column($points, 'cadence'), fn ($v) => is_numeric($v) && $v > 0));
        $powerValues = array_values(array_filter(array_column($points, 'power'), fn ($v) => is_numeric($v) && $v > 0));

        return [
            'startTimeIso' => $points[0]['time']->format('Y-m-d\TH:i:s\Z'),
            'durationSec' => $durationSec,
            'elapsedTimeSec' => $elapsedTimeSec,
            'movingTimeSec' => $movingTimeSec,
            'distanceM' => $distanceRounded,
            'sport' => 'run',
            'hr' => [
                'avgBpm' => count($hrValues) > 0 ? (int) round(array_sum($hrValues) / count($hrValues)) : null,
                'maxBpm' => count($hrValues) > 0 ? max($hrValues) : null,
            ],
            'hrSampleCount' => count($hrValues),
            'avgPaceSecPerKm' => $avgPace,
            'intensityBuckets' => $this->paceBuckets($movingTimeSec, $avgPace),
            'elevationGainMeters' => round($gain, 2),
            'elevationLossMeters' => round($loss, 2),
            'cadence' => [
                'avgSpm' => count($cadValues) > 0 ? (int) round(array_sum($cadValues) / count($cadValues)) : null,
                'maxSpm' => count($cadValues) > 0 ? max($cadValues) : null,
            ],
            'power' => [
                'avgWatts' => count($powerValues) > 0 ? (int) round(array_sum($powerValues) / count($powerValues)) : null,
                'maxWatts' => count($powerValues) > 0 ? max($powerValues) : null,
            ],
            'paceZones' => $this->paceZones($movingTimeSec, $avgPace),
            'dataAvailability' => [
                'gps' => true,
                'hr' => count($hrValues) > 0,
                'cadence' => count($cadValues) > 0,
                'power' => count($powerValues) > 0,
                'elevation' => $gain > 0 || $loss > 0,
                'movingTime' => true,
            ],
            'laps' => 1,
            'fileType' => 'gpx',
        ];
    }

    private function firstText(\DOMXPath $xpath, string $query, \DOMNode $context): ?string
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $text = trim((string) $nodes->item(0)->textContent);
        return $text === '' ? null : $text;
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @return array{z1Sec:int,z2Sec:int,z3Sec:int,z4Sec:int,z5Sec:int,totalSec:int}
     */
    private function paceBuckets(int $durationSec, ?int $avgPaceSecPerKm): array
    {
        $zone = ['z1Sec' => 0, 'z2Sec' => 0, 'z3Sec' => 0, 'z4Sec' => 0, 'z5Sec' => 0];
        if ($durationSec <= 0 || $avgPaceSecPerKm === null) {
            return $zone + ['totalSec' => max(0, $durationSec)];
        }
        $key = match (true) {
            $avgPaceSecPerKm >= 360 => 'z1Sec',
            $avgPaceSecPerKm >= 300 => 'z2Sec',
            $avgPaceSecPerKm >= 255 => 'z3Sec',
            $avgPaceSecPerKm >= 225 => 'z4Sec',
            default => 'z5Sec',
        };
        $zone[$key] = $durationSec;
        return $zone + ['totalSec' => $durationSec];
    }

    /**
     * @return array{z1Sec:int,z2Sec:int,z3Sec:int,z4Sec:int,z5Sec:int,totalSec:int,status:string}
     */
    private function paceZones(int $durationSec, ?int $avgPaceSecPerKm): array
    {
        return $this->paceBuckets($durationSec, $avgPaceSecPerKm) + [
            'status' => $avgPaceSecPerKm === null ? 'missing' : 'estimated',
        ];
    }

    private function intOrNull(?string $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(?string $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
