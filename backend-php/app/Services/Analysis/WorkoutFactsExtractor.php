<?php

namespace App\Services\Analysis;

use App\Models\Workout;
use App\Support\Analysis\Dto\WorkoutFactsDto;

/**
 * Provider-neutral extractor: zamienia rekord Workout (+ ewentualnie raw TCX)
 * na deterministyczny WorkoutFactsDto.
 *
 * Zasady:
 *  - funkcja czysta: ten sam wsad daje ten sam wynik,
 *  - pola, ktorych nie da sie policzyc, sa null - nie zgadujemy,
 *  - nie liczy stref, ACWR, regularnosci - to robi UserTrainingAnalysisService,
 *  - nie woła API ani providerow - dziala wylacznie na danych z modelu.
 */
class WorkoutFactsExtractor
{
    public const EXTRACTOR_VERSION = '1.0';

    /**
     * @var list<string>
     */
    private const KNOWN_SPORTS = ['run', 'trail_run', 'treadmill', 'walk', 'bike', 'other'];

    public function extract(Workout $workout): WorkoutFactsDto
    {
        $summary = is_array($workout->summary) ? $workout->summary : [];
        $meta = is_array($workout->workout_meta) ? $workout->workout_meta : [];

        $startedAt = $this->normalizeStartedAt($summary['startTimeIso'] ?? null);
        $durationSec = $this->intOrNull($summary['durationSec'] ?? null);
        $distanceMeters = $this->distanceFromSummary($summary);
        $sportKind = $this->mapSport($summary['sport'] ?? null, $meta);

        $avgHrBpm = $this->floatOrNull($summary['hr']['avgBpm'] ?? null);
        $maxHrBpm = $this->floatOrNull($summary['hr']['maxBpm'] ?? null);
        $hasHr = $avgHrBpm !== null || $maxHrBpm !== null;

        $avgPaceSecPerKm = $this->resolveAvgPace(
            $summary['avgPaceSecPerKm'] ?? null,
            $durationSec,
            $distanceMeters,
            $sportKind,
        );

        $rawTcxId = $this->resolveRawTcxId($workout);

        return new WorkoutFactsDto(
            workoutId: (string) $workout->id,
            userId: (string) $workout->user_id,
            source: $this->normalizeSource((string) ($workout->source ?? '')),
            sourceActivityId: $this->stringOrNull($workout->source_activity_id ?? null),
            startedAt: $startedAt,
            durationSec: $durationSec,
            movingTimeSec: $this->intOrNull($summary['movingTimeSec'] ?? null),
            distanceMeters: $distanceMeters,
            sportKind: $sportKind,
            hasGps: $this->resolveHasGps($workout, $rawTcxId),
            hasHr: $hasHr,
            hasCadence: $this->resolveHasCadence($summary),
            hasPower: $this->resolveHasPower($summary),
            hasElevation: $this->resolveHasElevation($summary),
            avgPaceSecPerKm: $avgPaceSecPerKm,
            avgHrBpm: $avgHrBpm,
            maxHrBpm: $maxHrBpm,
            hrSampleCount: $this->intOrZero($summary['hrSampleCount'] ?? null),
            elevationGainMeters: $this->floatOrNull($summary['elevationGainMeters'] ?? null),
            perceivedEffort: $this->resolvePerceivedEffort($meta),
            notes: $this->stringOrNull($meta['notes'] ?? null),
            rawProviderRefs: [
                'rawTcxId' => $rawTcxId,
                'rawFitId' => $this->stringOrNull($meta['rawFitId'] ?? null),
                'rawProviderPayloadId' => $this->stringOrNull($workout->source_activity_id ?? null),
            ],
            computedAt: now()->utc()->toIso8601String(),
            extractorVersion: self::EXTRACTOR_VERSION,
            elapsedTimeSec: $this->intOrNull($summary['elapsedTimeSec'] ?? null),
            avgCadenceSpm: $this->intOrNull($summary['cadence']['avgSpm'] ?? null),
            maxCadenceSpm: $this->intOrNull($summary['cadence']['maxSpm'] ?? null),
            avgPowerWatts: $this->intOrNull($summary['power']['avgWatts'] ?? null),
            maxPowerWatts: $this->intOrNull($summary['power']['maxWatts'] ?? null),
            elevationLossMeters: $this->floatOrNull($summary['elevationLossMeters'] ?? null),
            paceZones: is_array($summary['paceZones'] ?? null) ? $summary['paceZones'] : [],
            dataAvailability: $this->resolveDataAvailability($summary, $rawTcxId),
        );
    }

    /**
     * Bierze surowe `source` z workouta i sprowadza do dozwolonego słownika.
     * Legacy wartosci (np. 'MANUAL_UPLOAD') traktujemy jako 'tcx', zeby
     * stara historia nie kasowala kontraktu.
     */
    private function normalizeSource(string $raw): string
    {
        $lower = strtolower(trim($raw));

        return match (true) {
            $lower === '' => 'manual',
            str_contains($lower, 'garmin') => 'garmin',
            str_contains($lower, 'strava') => 'strava',
            str_contains($lower, 'fit') && ! str_contains($lower, 'fitness') => 'fit',
            str_contains($lower, 'gpx') => 'gpx',
            str_contains($lower, 'tcx'), str_contains($lower, 'manual_upload'), str_contains($lower, 'upload') => 'tcx',
            $lower === 'manual' => 'manual',
            default => 'manual',
        };
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function mapSport(mixed $rawSport, array $meta): string
    {
        $candidate = is_string($rawSport) ? strtolower(trim($rawSport)) : '';
        $metaKind = isset($meta['kind']) && is_string($meta['kind']) ? strtolower(trim($meta['kind'])) : '';

        if (in_array($candidate, self::KNOWN_SPORTS, true)) {
            // jezeli summary pokazuje 'run' a meta pokazuje 'treadmill' - zaufaj meta
            if ($candidate === 'run' && in_array($metaKind, ['treadmill', 'trail_run'], true)) {
                return $metaKind;
            }

            return $candidate;
        }

        if (str_contains($candidate, 'run')) {
            return 'run';
        }
        if (str_contains($candidate, 'walk')) {
            return 'walk';
        }
        if (str_contains($candidate, 'bik') || str_contains($candidate, 'cycl')) {
            return 'bike';
        }
        if (in_array($metaKind, self::KNOWN_SPORTS, true)) {
            return $metaKind;
        }

        return 'other';
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function distanceFromSummary(array $summary): ?float
    {
        $raw = $summary['distanceM'] ?? null;
        if (! is_numeric($raw)) {
            return null;
        }
        $val = (float) $raw;

        return $val > 0 ? $val : null;
    }

    private function resolveAvgPace(mixed $rawPace, ?int $durationSec, ?float $distanceMeters, string $sport): ?float
    {
        // 1) preferuj zapisana wartosc, jezeli ma sens
        if (is_numeric($rawPace)) {
            $val = (float) $rawPace;
            if ($val > 0) {
                return $val;
            }
        }

        // 2) policz tylko jezeli to bieg / trail / treadmill / walk z dystansem i czasem
        if (! in_array($sport, ['run', 'trail_run', 'treadmill', 'walk'], true)) {
            return null;
        }
        if ($durationSec === null || $durationSec <= 0) {
            return null;
        }
        if ($distanceMeters === null || $distanceMeters <= 0) {
            return null;
        }

        return round($durationSec / ($distanceMeters / 1000.0), 2);
    }

    private function resolveHasGps(Workout $workout, ?string $rawTcxId): bool
    {
        if ($rawTcxId !== null) {
            return true;
        }
        $meta = is_array($workout->workout_meta) ? $workout->workout_meta : [];
        if (isset($meta['hasGps'])) {
            return (bool) $meta['hasGps'];
        }
        $source = $this->normalizeSource((string) ($workout->source ?? ''));

        return in_array($source, ['garmin', 'strava', 'gpx'], true);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function resolveHasCadence(array $summary): bool
    {
        return isset($summary['cadence']) && is_array($summary['cadence']) && ! empty($summary['cadence']);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function resolveHasPower(array $summary): bool
    {
        return isset($summary['power']) && is_array($summary['power']) && ! empty($summary['power']);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function resolveHasElevation(array $summary): bool
    {
        if (isset($summary['elevationGainMeters']) && is_numeric($summary['elevationGainMeters']) && (float) $summary['elevationGainMeters'] > 0) {
            return true;
        }

        return isset($summary['elevation']) && is_array($summary['elevation']) && ! empty($summary['elevation']);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,bool>
     */
    private function resolveDataAvailability(array $summary, ?string $rawTcxId): array
    {
        $raw = is_array($summary['dataAvailability'] ?? null) ? $summary['dataAvailability'] : [];

        return [
            'gps' => array_key_exists('gps', $raw) ? (bool) $raw['gps'] : $rawTcxId !== null,
            'hr' => array_key_exists('hr', $raw) ? (bool) $raw['hr'] : isset($summary['hr']),
            'cadence' => array_key_exists('cadence', $raw) ? (bool) $raw['cadence'] : $this->resolveHasCadence($summary),
            'power' => array_key_exists('power', $raw) ? (bool) $raw['power'] : $this->resolveHasPower($summary),
            'elevation' => array_key_exists('elevation', $raw) ? (bool) $raw['elevation'] : $this->resolveHasElevation($summary),
            'movingTime' => array_key_exists('movingTime', $raw) ? (bool) $raw['movingTime'] : isset($summary['movingTimeSec']),
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function resolvePerceivedEffort(array $meta): ?int
    {
        foreach (['perceivedEffort', 'rpe', 'effort'] as $key) {
            $val = $meta[$key] ?? null;
            if (is_numeric($val)) {
                $intVal = (int) $val;
                if ($intVal >= 1 && $intVal <= 10) {
                    return $intVal;
                }
            }
        }

        return null;
    }

    private function resolveRawTcxId(Workout $workout): ?string
    {
        if (! $workout->relationLoaded('rawTcx')) {
            try {
                $workout->loadMissing('rawTcx');
            } catch (\Throwable) {
                return null;
            }
        }
        $raw = $workout->getRelation('rawTcx');
        if ($raw === null) {
            return null;
        }
        $id = $raw->getKey();

        return $id === null ? null : (string) $id;
    }

    private function normalizeStartedAt(mixed $raw): string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return now()->utc()->toIso8601String();
        }
        try {
            return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return now()->utc()->toIso8601String();
        }
    }

    private function intOrNull(mixed $val): ?int
    {
        if (! is_numeric($val)) {
            return null;
        }
        $int = (int) $val;

        return $int > 0 ? $int : null;
    }

    private function intOrZero(mixed $val): int
    {
        if (! is_numeric($val)) {
            return 0;
        }

        return max(0, (int) $val);
    }

    private function floatOrNull(mixed $val): ?float
    {
        if (! is_numeric($val)) {
            return null;
        }
        $float = (float) $val;

        return $float > 0 ? $float : null;
    }

    private function stringOrNull(mixed $val): ?string
    {
        if (! is_string($val)) {
            return null;
        }
        $trim = trim($val);

        return $trim === '' ? null : $trim;
    }
}
