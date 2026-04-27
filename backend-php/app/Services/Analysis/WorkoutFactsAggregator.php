<?php

namespace App\Services\Analysis;

use App\Support\Analysis\Dto\WorkoutFactsDto;
use Carbon\CarbonImmutable;

/**
 * Pure-function agregator: bierze liste WorkoutFactsDto i wylicza agregaty
 * (load 7d / 28d, ACWR, regularnosc, najdluzsze przerwy, etc.).
 *
 * Nie zna pojecia 'plan' ani 'AI'. Nie czyta bazy. Nie woła API.
 */
class WorkoutFactsAggregator
{
    /**
     * Prog ACWR powyzej ktorego flagujemy spike load. 1.5 to powszechnie
     * przyjmowany prog ostrzegawczy (Gabbett 2016).
     */
    public const SPIKE_ACWR_THRESHOLD = 1.5;

    /**
     * Minimalna dlugosc przerwy w dniach, ktora wpisujemy do gaps[].
     */
    public const GAP_MIN_DAYS = 7;

    /**
     * @param  list<WorkoutFactsDto>  $facts
     * @return array<string,mixed>
     */
    public function aggregate(array $facts, CarbonImmutable $now): array
    {
        $count = count($facts);

        if ($count === 0) {
            return $this->emptyAggregates();
        }

        $sorted = $this->sortByStartedAt($facts);
        $last = end($sorted);
        $lastAt = $this->parseStarted($last->startedAt);

        $runningLoad7d = $this->impactLoadMinutesWithinDays($sorted, $now, 7, 'runningLoadMin');
        $runningLoad28d = $this->impactLoadMinutesWithinDays($sorted, $now, 28, 'runningLoadMin');
        $crossTrainingFatigue7d = $this->impactLoadMinutesWithinDays($sorted, $now, 7, 'crossTrainingFatigueMin');
        $crossTrainingFatigue28d = $this->impactLoadMinutesWithinDays($sorted, $now, 28, 'crossTrainingFatigueMin');
        $overallFatigue7d = $this->impactLoadMinutesWithinDays($sorted, $now, 7, 'overallFatigueMin');
        $overallFatigue28d = $this->impactLoadMinutesWithinDays($sorted, $now, 28, 'overallFatigueMin');
        $acwrRunning = $this->acwrForLoad($runningLoad7d, $runningLoad28d);
        $acwrOverall = $this->acwrForLoad($overallFatigue7d, $overallFatigue28d);
        $runningLoadSpike = $this->isSpikeByAcwr($acwrRunning);
        $overallFatigueSpike = $this->isSpikeByAcwr($acwrOverall);

        return [
            'workoutCount' => $count,
            'workoutCount7d' => $this->countWithinDays($sorted, $now, 7),
            'workoutCount28d' => $this->countWithinDays($sorted, $now, 28),
            'lastWorkoutAt' => $lastAt?->toIso8601String(),
            'lastWorkoutWasDaysAgo' => $lastAt ? (int) abs($lastAt->diffInDays($now, false)) : null,
            'weeklyDistanceMetersAvg4w' => $this->weeklyDistanceAvg4w($sorted, $now),
            'weeklyDurationSecAvg4w' => $this->weeklyDurationAvg4w($sorted, $now),
            'longestRunMeters' => $this->longestRunMeters($sorted),
            'longestRunDurationSec' => $this->longestRunDurationSec($sorted),
            'avgPaceSecPerKm' => $this->weightedAvgPace($sorted),
            'avgHrBpm' => $this->avgHr($sorted),
            'maxHrObservedBpm' => $this->maxHrObserved($sorted),
            // Backward-compatible names now mean running load, not all activity minutes.
            'load7d' => $runningLoad7d,
            'load28d' => $runningLoad28d,
            'acwr' => $acwrRunning,
            'spikeLoad' => $runningLoadSpike || $overallFatigueSpike,
            'runningLoad7d' => $runningLoad7d,
            'runningLoad28d' => $runningLoad28d,
            'crossTrainingFatigue7d' => $crossTrainingFatigue7d,
            'crossTrainingFatigue28d' => $crossTrainingFatigue28d,
            'overallFatigue7d' => $overallFatigue7d,
            'overallFatigue28d' => $overallFatigue28d,
            'acwrRunning' => $acwrRunning,
            'acwrOverall' => $acwrOverall,
            'runningLoadSpike' => $runningLoadSpike,
            'overallFatigueSpike' => $overallFatigueSpike,
            'consistencyScore' => $this->consistencyScore($sorted, $now),
            'longestGapDays' => $this->longestGapDays($sorted, $now),
            'gaps' => $this->gaps($sorted),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyAggregates(): array
    {
        return [
            'workoutCount' => 0,
            'workoutCount7d' => 0,
            'workoutCount28d' => 0,
            'lastWorkoutAt' => null,
            'lastWorkoutWasDaysAgo' => null,
            'weeklyDistanceMetersAvg4w' => null,
            'weeklyDurationSecAvg4w' => null,
            'longestRunMeters' => null,
            'longestRunDurationSec' => null,
            'avgPaceSecPerKm' => null,
            'avgHrBpm' => null,
            'maxHrObservedBpm' => null,
            'load7d' => null,
            'load28d' => null,
            'acwr' => null,
            'spikeLoad' => false,
            'runningLoad7d' => null,
            'runningLoad28d' => null,
            'crossTrainingFatigue7d' => null,
            'crossTrainingFatigue28d' => null,
            'overallFatigue7d' => null,
            'overallFatigue28d' => null,
            'acwrRunning' => null,
            'acwrOverall' => null,
            'runningLoadSpike' => false,
            'overallFatigueSpike' => false,
            'consistencyScore' => null,
            'longestGapDays' => null,
            'gaps' => [],
        ];
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     * @return list<WorkoutFactsDto>
     */
    private function sortByStartedAt(array $facts): array
    {
        usort($facts, function (WorkoutFactsDto $a, WorkoutFactsDto $b) {
            return strcmp($a->startedAt, $b->startedAt);
        });

        return array_values($facts);
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     */
    private function countWithinDays(array $facts, CarbonImmutable $now, int $days): int
    {
        $threshold = $now->subDays($days);
        $count = 0;
        foreach ($facts as $f) {
            $at = $this->parseStarted($f->startedAt);
            if ($at !== null && $at->greaterThanOrEqualTo($threshold)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     */
    private function weeklyDistanceAvg4w(array $facts, CarbonImmutable $now): ?float
    {
        $threshold = $now->subDays(28);
        $sum = 0.0;
        $hasAny = false;
        foreach ($facts as $f) {
            $at = $this->parseStarted($f->startedAt);
            if ($at === null || $at->lessThan($threshold)) {
                continue;
            }
            if ($f->distanceMeters !== null && $f->distanceMeters > 0) {
                $sum += $f->distanceMeters;
                $hasAny = true;
            }
        }

        return $hasAny ? round($sum / 4.0, 2) : null;
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     */
    private function weeklyDurationAvg4w(array $facts, CarbonImmutable $now): ?float
    {
        $threshold = $now->subDays(28);
        $sum = 0;
        $hasAny = false;
        foreach ($facts as $f) {
            $at = $this->parseStarted($f->startedAt);
            if ($at === null || $at->lessThan($threshold)) {
                continue;
            }
            if ($f->durationSec !== null && $f->durationSec > 0) {
                $sum += $f->durationSec;
                $hasAny = true;
            }
        }

        return $hasAny ? round($sum / 4.0, 2) : null;
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     */
    private function longestRunMeters(array $facts): ?float
    {
        $max = null;
        foreach ($facts as $f) {
            if (! $this->isRunish($f->sportKind)) {
                continue;
            }
            if ($f->distanceMeters !== null && ($max === null || $f->distanceMeters > $max)) {
                $max = $f->distanceMeters;
            }
        }

        return $max;
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     */
    private function longestRunDurationSec(array $facts): ?float
    {
        $max = null;
        foreach ($facts as $f) {
            if (! $this->isRunish($f->sportKind)) {
                continue;
            }
            if ($f->durationSec !== null && ($max === null || $f->durationSec > $max)) {
                $max = (float) $f->durationSec;
            }
        }

        return $max;
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     */
    private function weightedAvgPace(array $facts): ?float
    {
        $totalDistance = 0.0;
        $totalSec = 0;
        foreach ($facts as $f) {
            if (! $this->isRunish($f->sportKind)) {
                continue;
            }
            if ($f->distanceMeters === null || $f->distanceMeters <= 0) {
                continue;
            }
            if ($f->durationSec === null || $f->durationSec <= 0) {
                continue;
            }
            $totalDistance += $f->distanceMeters;
            $totalSec += $f->durationSec;
        }
        if ($totalDistance <= 0) {
            return null;
        }

        return round($totalSec / ($totalDistance / 1000.0), 2);
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     */
    private function avgHr(array $facts): ?float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($facts as $f) {
            if ($f->avgHrBpm !== null && $f->avgHrBpm > 0) {
                $sum += $f->avgHrBpm;
                $count++;
            }
        }

        return $count > 0 ? round($sum / $count, 2) : null;
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     */
    private function maxHrObserved(array $facts): ?float
    {
        $max = null;
        foreach ($facts as $f) {
            if ($f->maxHrBpm !== null && $f->maxHrBpm > 0) {
                if ($max === null || $f->maxHrBpm > $max) {
                    $max = $f->maxHrBpm;
                }
            }
        }

        return $max;
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     */
    private function impactLoadMinutesWithinDays(array $facts, CarbonImmutable $now, int $days, string $key): ?float
    {
        $threshold = $now->subDays($days);
        $sum = 0.0;
        $hasAny = false;
        foreach ($facts as $f) {
            $at = $this->parseStarted($f->startedAt);
            if ($at === null || $at->lessThan($threshold)) {
                continue;
            }
            $impact = $this->activityImpact($f);
            if (isset($impact[$key]) && is_numeric($impact[$key])) {
                $sum += (float) $impact[$key];
                $hasAny = true;
            }
        }

        return $hasAny ? round($sum, 2) : null;
    }

    private function acwrForLoad(?float $load7, ?float $load28): ?float
    {
        if ($load7 === null || $load28 === null || $load28 <= 0) {
            return null;
        }
        $chronic = $load28 / 4.0;
        if ($chronic <= 0) {
            return null;
        }

        return round($load7 / $chronic, 2);
    }

    private function isSpikeByAcwr(?float $acwr): bool
    {
        return $acwr !== null && $acwr > self::SPIKE_ACWR_THRESHOLD;
    }

    /**
     * @return array<string,mixed>
     */
    private function activityImpact(WorkoutFactsDto $fact): array
    {
        if (isset($fact->activityImpact['runningLoadMin'], $fact->activityImpact['overallFatigueMin'])) {
            return $fact->activityImpact;
        }

        return (new ActivityImpactService())->impact(
            $fact->sportKind,
            $fact->sportSubtype,
            $fact->durationSec,
            $fact->elevationGainMeters,
            $fact->perceivedEffort,
        );
    }

    /**
     * Consistency w oknie 4 tygodni: udzial tygodni z minimum jednym treningiem.
     * 1.0 = w kazdym z ostatnich 4 tygodni byl trening, 0.0 = w zadnym.
     *
     * @param  list<WorkoutFactsDto>  $facts
     */
    private function consistencyScore(array $facts, CarbonImmutable $now): ?float
    {
        $threshold = $now->subDays(28);
        $weeksWithWorkout = [];
        $hasAny = false;
        foreach ($facts as $f) {
            $at = $this->parseStarted($f->startedAt);
            if ($at === null || $at->lessThan($threshold)) {
                continue;
            }
            $hasAny = true;
            $weekStart = $at->startOfWeek()->format('Y-m-d');
            $weeksWithWorkout[$weekStart] = true;
        }
        if (! $hasAny) {
            return null;
        }

        return round(min(1.0, count($weeksWithWorkout) / 4.0), 2);
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     */
    private function longestGapDays(array $facts, CarbonImmutable $now): ?int
    {
        if (count($facts) === 0) {
            return null;
        }
        $longest = 0;

        // przerwa miedzy kolejnymi treningami
        for ($i = 1; $i < count($facts); $i++) {
            $prev = $this->parseStarted($facts[$i - 1]->startedAt);
            $curr = $this->parseStarted($facts[$i]->startedAt);
            if ($prev === null || $curr === null) {
                continue;
            }
            $diff = (int) $curr->diffInDays($prev, false);
            $diff = abs($diff);
            if ($diff > $longest) {
                $longest = $diff;
            }
        }

        // przerwa od ostatniego treningu do teraz - tez liczy
        $last = $this->parseStarted(end($facts)->startedAt);
        if ($last !== null) {
            $diff = abs((int) $now->diffInDays($last, false));
            if ($diff > $longest) {
                $longest = $diff;
            }
        }

        return $longest;
    }

    /**
     * @param  list<WorkoutFactsDto>  $facts
     * @return list<array{fromDate:string,toDate:string,days:int}>
     */
    private function gaps(array $facts): array
    {
        $gaps = [];
        for ($i = 1; $i < count($facts); $i++) {
            $prev = $this->parseStarted($facts[$i - 1]->startedAt);
            $curr = $this->parseStarted($facts[$i]->startedAt);
            if ($prev === null || $curr === null) {
                continue;
            }
            $days = abs((int) $curr->diffInDays($prev, false));
            if ($days >= self::GAP_MIN_DAYS) {
                $gaps[] = [
                    'fromDate' => $prev->format('Y-m-d'),
                    'toDate' => $curr->format('Y-m-d'),
                    'days' => $days,
                ];
            }
        }

        return $gaps;
    }

    private function isRunish(string $sport): bool
    {
        return in_array($sport, ['run', 'trail_run', 'treadmill'], true);
    }

    private function parseStarted(string $iso): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($iso)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
