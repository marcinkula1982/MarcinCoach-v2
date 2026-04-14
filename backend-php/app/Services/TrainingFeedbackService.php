<?php

namespace App\Services;

use App\Models\Workout;
use Carbon\Carbon;

class TrainingFeedbackService
{
    public function getFeedbackForUser(int $userId, int $days = 28): array
    {
        $workouts = Workout::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get(['id', 'created_at', 'summary', 'workout_meta']);

        $rows = $workouts->map(function (Workout $workout) {
            $summary = is_array($workout->summary) ? $workout->summary : [];
            $meta = is_array($workout->workout_meta) ? $workout->workout_meta : [];

            $workoutDt = $this->getWorkoutDt($summary, $workout->created_at);

            return [
                'id' => $workout->id,
                'workoutDt' => $workoutDt,
                'planCompliance' => $this->mapCompliance($meta['planCompliance'] ?? null),
                'rpe' => $meta['rpe'] ?? null,
                'fatigueFlag' => $meta['fatigueFlag'] ?? null,
                'note' => $meta['note'] ?? null,
            ];
        })->values();

        $to = $rows->isNotEmpty()
            ? $rows->max(fn (array $row) => $row['workoutDt']->getTimestamp())
            : 0;
        $toDt = Carbon::createFromTimestampUTC((int) $to);
        $fromDt = $toDt->copy()->subSeconds($days * 24 * 60 * 60);

        $filtered = $rows
            ->filter(fn (array $row) => $row['workoutDt']->greaterThanOrEqualTo($fromDt) && $row['workoutDt']->lessThanOrEqualTo($toDt))
            ->values();

        $totalSessions = $filtered->count();
        $planned = $filtered->where('planCompliance', 'planned')->count();
        $modified = $filtered->where('planCompliance', 'modified')->count();
        $unplanned = $filtered->where('planCompliance', 'unplanned')->count();
        $unknown = $filtered->where('planCompliance', 'unknown')->count();

        $plannedPct = $totalSessions > 0 ? round(($planned / $totalSessions) * 100, 2) : 0.0;
        $modifiedPct = $totalSessions > 0 ? round(($modified / $totalSessions) * 100, 2) : 0.0;
        $unplannedPct = $totalSessions > 0 ? round(($unplanned / $totalSessions) * 100, 2) : 0.0;

        $validRpeValues = $filtered
            ->pluck('rpe')
            ->filter(fn ($rpe) => is_numeric($rpe) && is_finite((float) $rpe) && (float) $rpe >= 1 && (float) $rpe <= 10)
            ->map(fn ($rpe) => (float) $rpe)
            ->values();

        $rpeSamples = $validRpeValues->count();
        $rpeAvg = $rpeSamples > 0 ? round($validRpeValues->avg(), 1) : null;
        $rpeP50 = $rpeSamples > 0 ? round($this->median($validRpeValues->all()), 1) : null;

        $fatigueTrueCount = $filtered->filter(fn (array $row) => $row['fatigueFlag'] === true)->count();
        $fatigueFalseCount = $filtered->filter(fn (array $row) => $row['fatigueFlag'] === false)->count();

        $notesWithValues = $filtered
            ->filter(function (array $row) {
                return is_string($row['note']) && trim($row['note']) !== '';
            })
            ->sortByDesc(fn (array $row) => $row['workoutDt']->getTimestamp())
            ->take(5)
            ->map(function (array $row) {
                return [
                    'workoutId' => $row['id'],
                    'workoutDtIso' => $row['workoutDt']->toISOString(),
                    'note' => trim($row['note']),
                ];
            })
            ->values()
            ->all();

        $notesSamples = $filtered->filter(function (array $row) {
            return is_string($row['note']) && trim($row['note']) !== '';
        })->count();

        $result = [
            'generatedAtIso' => $toDt->toISOString(),
            'windowDays' => $days,
            'counts' => [
                'totalSessions' => $totalSessions,
                'planned' => $planned,
                'modified' => $modified,
                'unplanned' => $unplanned,
                'unknown' => $unknown,
            ],
            'complianceRate' => [
                'plannedPct' => $plannedPct,
                'modifiedPct' => $modifiedPct,
                'unplannedPct' => $unplannedPct,
            ],
            'rpe' => [
                'samples' => $rpeSamples,
            ],
            'fatigue' => [
                'trueCount' => $fatigueTrueCount,
                'falseCount' => $fatigueFalseCount,
            ],
            'notes' => [
                'samples' => $notesSamples,
                'last5' => $notesWithValues,
            ],
        ];

        if ($rpeAvg !== null) {
            $result['rpe']['avg'] = $rpeAvg;
        }
        if ($rpeP50 !== null) {
            $result['rpe']['p50'] = $rpeP50;
        }

        return $result;
    }

    private function getWorkoutDt(array $summary, Carbon $createdAt): Carbon
    {
        $startTimeIso = $summary['startTimeIso'] ?? null;
        if (is_string($startTimeIso)) {
            try {
                return Carbon::parse($startTimeIso)->utc();
            } catch (\Throwable) {
                // fallback below
            }
        }

        return $createdAt->copy()->utc();
    }

    private function mapCompliance(mixed $value): string
    {
        if ($value === 'planned' || $value === 'modified' || $value === 'unplanned') {
            return $value;
        }

        return 'unknown';
    }

    /**
     * @param array<int,float> $arr
     */
    private function median(array $arr): float
    {
        if (count($arr) === 0) {
            return 0.0;
        }

        sort($arr);
        $mid = intdiv(count($arr), 2);

        if (count($arr) % 2 === 0) {
            return ($arr[$mid - 1] + $arr[$mid]) / 2;
        }

        return $arr[$mid];
    }
}
