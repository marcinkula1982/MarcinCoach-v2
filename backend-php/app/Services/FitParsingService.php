<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Minimal FIT activity parser for MVP imports.
 *
 * It intentionally supports the common activity records we need for coaching
 * (record/session messages) and fails loudly for files that are not FIT. Full
 * developer-field semantics can be added later without changing callers.
 */
class FitParsingService
{
    private const FIT_EPOCH_UNIX = 631065600; // 1989-12-31T00:00:00Z

    /** @var array<int,array{global:int,fields:array<int,array{num:int,size:int,base:int}>}> */
    private array $definitions = [];

    /**
     * @return array<string,mixed>
     */
    public function parse(string $binary): array
    {
        if (strlen($binary) < 14 || !str_contains(substr($binary, 0, 14), '.FIT')) {
            throw new \InvalidArgumentException('Invalid FIT file format');
        }

        $headerSize = ord($binary[0]);
        if (!in_array($headerSize, [12, 14], true) || strlen($binary) < $headerSize + 4) {
            throw new \InvalidArgumentException('Unsupported FIT header');
        }

        $dataSize = $this->u32($binary, 4, false);
        $dataStart = $headerSize;
        $dataEnd = min(strlen($binary), $dataStart + $dataSize);
        if ($dataEnd <= $dataStart) {
            throw new \InvalidArgumentException('Empty FIT data section');
        }

        $records = [];
        $session = [];
        $offset = $dataStart;
        while ($offset < $dataEnd) {
            $header = ord($binary[$offset]);
            $offset++;

            if (($header & 0x80) !== 0) {
                $local = ($header >> 5) & 0x03;
                $this->readDataMessage($binary, $offset, $local, $records, $session);
                continue;
            }

            $isDefinition = ($header & 0x40) !== 0;
            $local = $header & 0x0f;
            if ($isDefinition) {
                $this->readDefinitionMessage($binary, $offset, $local);
            } else {
                $this->readDataMessage($binary, $offset, $local, $records, $session);
            }
        }

        if (empty($records) && empty($session)) {
            throw new \InvalidArgumentException('No FIT activity records found');
        }

        return $this->buildParsedBlob($records, $session);
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<string,mixed> $session
     */
    private function buildParsedBlob(array $records, array $session): array
    {
        usort($records, fn (array $a, array $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        $firstTimestamp = $records[0]['timestamp'] ?? $session['start_time'] ?? $session['timestamp'] ?? null;
        if (!is_numeric($firstTimestamp)) {
            throw new \InvalidArgumentException('FIT file has no timestamp');
        }

        $lastRecord = !empty($records) ? $records[count($records) - 1] : [];
        $distanceM = $this->positiveFloat($session['total_distance'] ?? null)
            ?? $this->positiveFloat($lastRecord['distance'] ?? null)
            ?? 0.0;
        $elapsedTimeSec = $this->positiveInt($session['total_elapsed_time'] ?? null);
        $movingTimeSec = $this->positiveInt($session['total_timer_time'] ?? null);

        if ($elapsedTimeSec === null && count($records) >= 2) {
            $elapsedTimeSec = max(1, (int) (($records[count($records) - 1]['timestamp'] ?? 0) - ($records[0]['timestamp'] ?? 0)));
        }
        if ($movingTimeSec === null) {
            $movingTimeSec = $elapsedTimeSec ?? 1;
        }
        $durationSec = $elapsedTimeSec ?? $movingTimeSec;

        $hrValues = $this->numericColumn($records, 'heart_rate', 30, 230);
        $cadValues = $this->numericColumn($records, 'cadence', 1, 255);
        $powerValues = $this->numericColumn($records, 'power', 1, 3000);

        $elevationGain = $this->positiveFloat($session['total_ascent'] ?? null) ?? $this->computeElevationDelta($records, true);
        $elevationLoss = $this->positiveFloat($session['total_descent'] ?? null) ?? $this->computeElevationDelta($records, false);
        $avgPace = $distanceM > 0 && $movingTimeSec > 0 ? (int) round($movingTimeSec / ($distanceM / 1000.0)) : null;

        return [
            'startTimeIso' => CarbonImmutable::createFromTimestampUTC(self::FIT_EPOCH_UNIX + (int) $firstTimestamp)->format('Y-m-d\TH:i:s\Z'),
            'durationSec' => (int) $durationSec,
            'elapsedTimeSec' => (int) $durationSec,
            'movingTimeSec' => (int) $movingTimeSec,
            'distanceM' => (int) round($distanceM),
            'sport' => $this->mapSport((int) ($session['sport'] ?? 1)),
            'hr' => [
                'avgBpm' => $this->positiveInt($session['avg_heart_rate'] ?? null) ?? $this->avgInt($hrValues),
                'maxBpm' => $this->positiveInt($session['max_heart_rate'] ?? null) ?? (!empty($hrValues) ? max($hrValues) : null),
            ],
            'hrSampleCount' => count($hrValues),
            'avgPaceSecPerKm' => $avgPace,
            'intensityBuckets' => $this->paceBuckets((int) $movingTimeSec, $avgPace),
            'elevationGainMeters' => round($elevationGain ?? 0, 2),
            'elevationLossMeters' => round($elevationLoss ?? 0, 2),
            'cadence' => [
                'avgSpm' => $this->positiveInt($session['avg_cadence'] ?? null) ?? $this->avgInt($cadValues),
                'maxSpm' => $this->positiveInt($session['max_cadence'] ?? null) ?? (!empty($cadValues) ? max($cadValues) : null),
            ],
            'power' => [
                'avgWatts' => $this->positiveInt($session['avg_power'] ?? null) ?? $this->avgInt($powerValues),
                'maxWatts' => $this->positiveInt($session['max_power'] ?? null) ?? (!empty($powerValues) ? max($powerValues) : null),
            ],
            'paceZones' => $this->paceBuckets((int) $movingTimeSec, $avgPace) + [
                'status' => $avgPace === null ? 'missing' : 'estimated',
            ],
            'dataAvailability' => [
                'gps' => count(array_filter($records, fn ($r) => isset($r['position_lat'], $r['position_long']))) > 0,
                'hr' => count($hrValues) > 0,
                'cadence' => count($cadValues) > 0,
                'power' => count($powerValues) > 0,
                'elevation' => ($elevationGain ?? 0) > 0 || ($elevationLoss ?? 0) > 0,
                'movingTime' => $movingTimeSec !== null,
            ],
            'laps' => 1,
            'fileType' => 'fit',
        ];
    }

    private function readDefinitionMessage(string $binary, int &$offset, int $local): void
    {
        $offset++; // reserved
        $architecture = ord($binary[$offset] ?? "\0");
        $offset++;
        $littleEndian = $architecture === 0;
        $global = $this->u16($binary, $offset, $littleEndian);
        $offset += 2;
        $fieldCount = ord($binary[$offset] ?? "\0");
        $offset++;

        $fields = [];
        for ($i = 0; $i < $fieldCount; $i++) {
            $fields[] = [
                'num' => ord($binary[$offset] ?? "\0"),
                'size' => ord($binary[$offset + 1] ?? "\0"),
                'base' => ord($binary[$offset + 2] ?? "\0"),
            ];
            $offset += 3;
        }

        $this->definitions[$local] = ['global' => $global, 'fields' => $fields, 'littleEndian' => $littleEndian];
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<string,mixed> $session
     */
    private function readDataMessage(string $binary, int &$offset, int $local, array &$records, array &$session): void
    {
        if (!isset($this->definitions[$local])) {
            return;
        }
        $definition = $this->definitions[$local];
        $values = [];
        foreach ($definition['fields'] as $field) {
            $raw = substr($binary, $offset, $field['size']);
            $offset += $field['size'];
            $values[$field['num']] = $this->decodeField($raw, $field['base'], (bool) ($definition['littleEndian'] ?? true));
        }

        $global = (int) $definition['global'];
        if ($global === 20) {
            $records[] = $this->mapRecord($values);
        } elseif ($global === 18) {
            $session = array_merge($session, $this->mapSession($values));
        }
    }

    /**
     * @param array<int,mixed> $values
     * @return array<string,mixed>
     */
    private function mapRecord(array $values): array
    {
        return [
            'timestamp' => $values[253] ?? null,
            'position_lat' => $values[0] ?? null,
            'position_long' => $values[1] ?? null,
            'altitude' => $this->scaled($values[78] ?? $values[2] ?? null, 5, 500),
            'heart_rate' => $values[3] ?? null,
            'cadence' => $values[4] ?? null,
            'distance' => $this->scaled($values[5] ?? null, 100, 0),
            'speed' => $this->scaled($values[73] ?? $values[6] ?? null, 1000, 0),
            'power' => $values[7] ?? null,
        ];
    }

    /**
     * @param array<int,mixed> $values
     * @return array<string,mixed>
     */
    private function mapSession(array $values): array
    {
        return [
            'timestamp' => $values[253] ?? null,
            'start_time' => $values[2] ?? null,
            'sport' => $values[5] ?? null,
            'total_elapsed_time' => $this->scaled($values[7] ?? null, 1000, 0),
            'total_timer_time' => $this->scaled($values[8] ?? null, 1000, 0),
            'total_distance' => $this->scaled($values[9] ?? null, 100, 0),
            'avg_speed' => $this->scaled($values[14] ?? null, 1000, 0),
            'max_speed' => $this->scaled($values[15] ?? null, 1000, 0),
            'avg_heart_rate' => $values[16] ?? null,
            'max_heart_rate' => $values[17] ?? null,
            'avg_cadence' => $values[18] ?? null,
            'max_cadence' => $values[19] ?? null,
            'avg_power' => $values[20] ?? null,
            'max_power' => $values[21] ?? null,
            'total_ascent' => $values[22] ?? null,
            'total_descent' => $values[23] ?? null,
        ];
    }

    private function decodeField(string $raw, int $base, bool $littleEndian): mixed
    {
        $type = $base & 0x1f;
        $size = strlen($raw);
        if ($size === 0) {
            return null;
        }
        return match ($type) {
            0x00, 0x0d => ord($raw[0]),
            0x01 => unpack('c', $raw[0])[1],
            0x02, 0x0a => $this->u8($raw),
            0x83 => $this->s16($raw, $littleEndian),
            0x84, 0x8b => $this->u16($raw, 0, $littleEndian),
            0x85 => $this->s32($raw, $littleEndian),
            0x86, 0x8c => $this->u32($raw, 0, $littleEndian),
            default => null,
        };
    }

    private function u8(string $raw): int
    {
        return ord($raw[0]);
    }

    private function u16(string $raw, int $offset, bool $littleEndian): int
    {
        $fmt = $littleEndian ? 'v' : 'n';
        return unpack($fmt, substr($raw, $offset, 2))[1];
    }

    private function s16(string $raw, bool $littleEndian): int
    {
        $u = $this->u16($raw, 0, $littleEndian);
        return $u >= 0x8000 ? $u - 0x10000 : $u;
    }

    private function u32(string $raw, int $offset, bool $littleEndian): int
    {
        $fmt = $littleEndian ? 'V' : 'N';
        return unpack($fmt, substr($raw, $offset, 4))[1];
    }

    private function s32(string $raw, bool $littleEndian): int
    {
        $u = $this->u32($raw, 0, $littleEndian);
        return $u >= 0x80000000 ? $u - 0x100000000 : $u;
    }

    private function scaled(mixed $value, float $scale, float $offset): ?float
    {
        return is_numeric($value) ? ((float) $value / $scale) - $offset : null;
    }

    /**
     * @param array<int,array<string,mixed>> $records
     */
    private function computeElevationDelta(array $records, bool $gain): ?float
    {
        $sum = 0.0;
        $has = false;
        for ($i = 1; $i < count($records); $i++) {
            if (!is_numeric($records[$i - 1]['altitude'] ?? null) || !is_numeric($records[$i]['altitude'] ?? null)) {
                continue;
            }
            $delta = (float) $records[$i]['altitude'] - (float) $records[$i - 1]['altitude'];
            if ($gain && $delta > 0) {
                $sum += $delta;
                $has = true;
            } elseif (!$gain && $delta < 0) {
                $sum += abs($delta);
                $has = true;
            }
        }
        return $has ? $sum : null;
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @return list<int>
     */
    private function numericColumn(array $records, string $key, int $min, int $max): array
    {
        $out = [];
        foreach ($records as $record) {
            $value = $record[$key] ?? null;
            if (is_numeric($value) && $value >= $min && $value <= $max) {
                $out[] = (int) $value;
            }
        }
        return $out;
    }

    private function mapSport(int $sport): string
    {
        return match ($sport) {
            1 => 'run',
            2 => 'bike',
            5 => 'swim',
            11 => 'walk_hike',
            default => 'other',
        };
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

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) round((float) $value) : null;
    }

    private function positiveFloat(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /**
     * @param list<int> $values
     */
    private function avgInt(array $values): ?int
    {
        return count($values) > 0 ? (int) round(array_sum($values) / count($values)) : null;
    }
}
