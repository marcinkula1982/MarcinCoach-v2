<?php

namespace App\Services;

use App\Models\Workout;
use App\Support\WorkoutSourceContract;
use Carbon\CarbonImmutable;

/**
 * Fuzzy match of an incoming activity against workouts already stored
 * from a DIFFERENT source for the same user.
 *
 * Rule (MVP, first-wins):
 *  - same user, different canonical source,
 *  - |start difference| <= 10 min (compared on UTC epoch, never on the ISO string),
 *  - |duration difference| <= 5% of the longer duration,
 *  - |distance difference| <= 3% of the longer distance (both > 0),
 *    or both distances equal to 0.
 *
 * On a hit the caller must NOT create a new workout; it records a DEDUPED
 * import event pointing at the existing workout. The existing (first) one stays.
 *
 * MANUAL_CHECK_IN is excluded on both sides: its start time is often an
 * estimate (noon fallback) and dropping a real device workout in favour of a
 * check-in would lose data. Source priority is a separate, later decision.
 */
class CrossSourceWorkoutDedupeService
{
    public const MAX_START_DIFF_SEC = 600;

    public const MAX_DURATION_DIFF_RATIO = 0.05;

    public const MAX_DISTANCE_DIFF_RATIO = 0.03;

    /**
     * Candidate pre-filter on the stored ISO string uses a +/- 1 day margin so
     * that mixed ISO representations (Z vs +00:00 vs +02:00) still land in the
     * window; the precise check is done on parsed UTC timestamps.
     */
    private const CANDIDATE_MARGIN_DAYS = 1;

    /** @var list<string> */
    private const EXCLUDED_SOURCES = [WorkoutSourceContract::MANUAL_CHECK_IN];

    public function findMatch(
        int $userId,
        string $canonicalSource,
        string $startTimeIso,
        int $durationSec,
        int $distanceM
    ): ?Workout {
        if ($durationSec <= 0 || $distanceM < 0) {
            return null;
        }
        if (in_array($canonicalSource, self::EXCLUDED_SOURCES, true)) {
            return null;
        }

        $start = $this->parseUtc($startTimeIso);
        if ($start === null) {
            return null;
        }

        $lo = $start->subDays(self::CANDIDATE_MARGIN_DAYS)->format('Y-m-d\TH:i:s\Z');
        $hi = $start->addDays(self::CANDIDATE_MARGIN_DAYS)->format('Y-m-d\TH:i:s\Z');

        $candidates = Workout::query()
            ->where('user_id', $userId)
            ->where('source', '!=', $canonicalSource)
            ->whereNotIn('source', self::EXCLUDED_SOURCES)
            ->where('action', '!=', 'preview-only')
            ->whereBetween('summary->startTimeIso', [$lo, $hi])
            ->orderBy('id')
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->matches($candidate, $start, $durationSec, $distanceM)) {
                return $candidate;
            }
        }

        return null;
    }

    private function matches(Workout $candidate, CarbonImmutable $start, int $durationSec, int $distanceM): bool
    {
        $summary = is_array($candidate->summary) ? $candidate->summary : [];

        $candidateStart = $this->parseUtc((string) ($summary['startTimeIso'] ?? ''));
        if ($candidateStart === null) {
            return false;
        }
        if (abs($candidateStart->getTimestamp() - $start->getTimestamp()) > self::MAX_START_DIFF_SEC) {
            return false;
        }

        $candidateDuration = $this->intFromSummary($summary, 'durationSec');
        if ($candidateDuration <= 0) {
            return false;
        }
        $durationTolerance = self::MAX_DURATION_DIFF_RATIO * max($candidateDuration, $durationSec);
        if (abs($candidateDuration - $durationSec) > $durationTolerance) {
            return false;
        }

        $candidateDistance = $this->intFromSummary($summary, 'distanceM');
        if ($candidateDistance > 0 && $distanceM > 0) {
            $distanceTolerance = self::MAX_DISTANCE_DIFF_RATIO * max($candidateDistance, $distanceM);
            if (abs($candidateDistance - $distanceM) > $distanceTolerance) {
                return false;
            }
        } elseif ($candidateDistance !== $distanceM) {
            // One side has a distance, the other does not: treat as different activities.
            return false;
        }

        return true;
    }

    /**
     * Flat summary field with fallback to trimmed/original (manual create() shape).
     *
     * @param array<string,mixed> $summary
     */
    private function intFromSummary(array $summary, string $key): int
    {
        $raw = $summary[$key] ?? $summary['trimmed'][$key] ?? $summary['original'][$key] ?? 0;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    private function parseUtc(string $iso): ?CarbonImmutable
    {
        if (trim($iso) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($iso, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
