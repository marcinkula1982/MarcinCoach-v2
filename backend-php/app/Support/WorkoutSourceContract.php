<?php

namespace App\Support;

/**
 * Single source of truth for workout source values, normalization,
 * and dedupe key generation — matches the Node.js canonical contract.
 *
 * Node canonical source enum: MANUAL_UPLOAD | GARMIN | STRAVA
 * Node dedupeKey format:
 *   - with activity id:  SOURCE:sourceActivityId
 *   - without activity id: SOURCE:t=<startTimeIso>:d=<round5s>:m=<round10m>
 */
class WorkoutSourceContract
{
    const MANUAL_UPLOAD = 'MANUAL_UPLOAD';
    const GARMIN = 'GARMIN';
    const STRAVA = 'STRAVA';

    /**
     * Accepted boundary input values → canonical uppercase storage value.
     * 'tcx' and 'manual' both map to MANUAL_UPLOAD (matching Node MANUAL_UPLOAD).
     *
     * @var array<string,string>
     */
    private static array $sourceMap = [
        'manual'        => self::MANUAL_UPLOAD,
        'manual_upload' => self::MANUAL_UPLOAD,
        'tcx'           => self::MANUAL_UPLOAD,
        'garmin'        => self::GARMIN,
        'strava'        => self::STRAVA,
    ];

    /**
     * Normalize an incoming source string to its canonical uppercase value.
     * Unrecognized values are upper-cased and stored as-is (no silent data loss).
     */
    public static function normalize(string $input): string
    {
        $lower = strtolower(trim($input));
        return self::$sourceMap[$lower] ?? strtoupper($lower);
    }

    /**
     * Normalize an optional activity/source ID: trim whitespace; return null for empty.
     */
    public static function normalizeActivityId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $trimmed = trim($id);
        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Build a dedupe key that is compatible with the Node.js canonical format.
     *
     * With activity id:     SOURCE:sourceActivityId
     * Without activity id:  SOURCE:t=<startTimeIso>:d=<durationRoundedTo5s>:m=<distanceRoundedTo10m>
     *
     * @param string  $canonicalSource     Already-normalized uppercase source value.
     * @param ?string $normalizedActivityId Already-normalized (trimmed/null) activity id.
     */
    public static function buildDedupeKey(
        string $canonicalSource,
        ?string $normalizedActivityId,
        string $startTimeIso,
        int $durationSec,
        int $distanceM
    ): string {
        if ($normalizedActivityId !== null) {
            return "{$canonicalSource}:{$normalizedActivityId}";
        }

        $durationNorm = (int) (round($durationSec / 5) * 5);
        $distanceNorm = (int) (round($distanceM / 10) * 10);

        return "{$canonicalSource}:t={$startTimeIso}:d={$durationNorm}:m={$distanceNorm}";
    }
}
