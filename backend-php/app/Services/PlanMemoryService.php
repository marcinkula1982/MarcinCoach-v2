<?php

namespace App\Services;

use App\Models\Workout;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * M3/M4 beyond current scope — Etap C.
 *
 * Pamięć planistyczna tygodnia. Zarządza tabelą training_weeks.
 *  - upsertWeekFromPlan()  — po wygenerowaniu planu
 *  - updateWeekActuals()   — po imporcie/analizie workoutów
 *  - getRecentWeeks()      — historia ostatnich N tygodni
 *  - getWeekGoalMet()      — czy cel został osiągnięty (planned vs actual >= 80%)
 */
class PlanMemoryService
{
    /**
     * @param array<string,mixed> $planOutput    wynik WeeklyPlanService::generatePlan()
     * @param array<string,mixed> $blockContext  wynik BlockPeriodizationService::resolve()
     */
    public function upsertWeekFromPlan(int $userId, array $planOutput, array $blockContext): void
    {
        $weekStart = $this->resolveWeekStartDate($planOutput);
        $weekEnd = $weekStart->copy()->addDays(6);

        $sessions = $planOutput['sessions'] ?? [];
        $plannedTotalMin = (int) array_reduce(
            is_array($sessions) ? $sessions : [],
            fn ($acc, $s) => $acc + (int) ($s['durationMin'] ?? 0),
            0,
        );
        $plannedQualityCount = 0;
        if (is_array($sessions)) {
            foreach ($sessions as $s) {
                $type = (string) ($s['type'] ?? '');
                if (in_array($type, ['quality', 'threshold', 'intervals', 'fartlek', 'tempo'], true)) {
                    $plannedQualityCount++;
                }
            }
        }

        $decisionLog = [
            'blockContext' => $blockContext,
            'summary' => $planOutput['summary'] ?? null,
            'appliedAdjustmentsCodes' => $planOutput['appliedAdjustmentsCodes'] ?? null,
        ];

        $now = now();
        $existing = DB::table('training_weeks')
            ->where('user_id', $userId)
            ->where('week_start_date', $weekStart->toDateString())
            ->first(['id', 'actual_total_min', 'actual_quality_count']);

        $payload = [
            'user_id' => $userId,
            'week_start_date' => $weekStart->toDateString(),
            'week_end_date' => $weekEnd->toDateString(),
            'block_type' => $blockContext['block_type'] ?? null,
            'week_role' => $blockContext['week_role'] ?? null,
            'block_goal' => $blockContext['block_goal'] ?? null,
            'key_capability_focus' => $blockContext['key_capability_focus'] ?? null,
            'load_direction' => $blockContext['load_direction'] ?? null,
            'planned_total_min' => $plannedTotalMin,
            'planned_quality_count' => $plannedQualityCount,
            'decision_log' => json_encode($decisionLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('training_weeks')
                ->where('id', $existing->id)
                ->update($payload);
        } else {
            $payload['created_at'] = $now;
            DB::table('training_weeks')->insert($payload);
        }

        // Po upserctie — odśwież actuals i goal_met
        $this->updateWeekActuals($userId, $weekStart->toDateString());
    }

    public function updateWeekActuals(int $userId, string $weekStartDate): void
    {
        try {
            $start = CarbonImmutable::parse($weekStartDate)->startOfDay();
        } catch (\Throwable) {
            return;
        }
        $end = $start->addDays(7); // half-open: [start, start+7d)

        $workouts = Workout::query()
            ->where('user_id', $userId)
            ->get(['id', 'summary', 'created_at']);

        $actualTotalMin = 0;
        $actualQualityCount = 0;
        foreach ($workouts as $w) {
            $summary = is_array($w->summary) ? $w->summary : [];
            $dt = $this->resolveWorkoutDt($summary, $w->created_at);
            if ($dt->lessThan(Carbon::instance($start->toDateTime())) || $dt->greaterThanOrEqualTo(Carbon::instance($end->toDateTime()))) {
                continue;
            }
            $sec = $this->extractDurationSec($summary);
            if ($sec > 0) {
                $actualTotalMin += (int) round($sec / 60);
            }
            if ($this->looksLikeQualityWorkout($summary)) {
                $actualQualityCount++;
            }
        }

        $row = DB::table('training_weeks')
            ->where('user_id', $userId)
            ->where('week_start_date', $start->toDateString())
            ->first(['id', 'planned_total_min']);

        if (!$row) {
            return;
        }

        $planned = (int) ($row->planned_total_min ?? 0);
        $goalMet = null;
        if ($planned > 0) {
            $goalMet = ($actualTotalMin / $planned) >= 0.80 ? 1 : 0;
        }

        DB::table('training_weeks')
            ->where('id', $row->id)
            ->update([
                'actual_total_min' => $actualTotalMin,
                'actual_quality_count' => $actualQualityCount,
                'goal_met' => $goalMet,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<int,array<string,mixed>> malejąco po week_start_date
     */
    public function getRecentWeeks(int $userId, int $count = 6): array
    {
        $rows = DB::table('training_weeks')
            ->where('user_id', $userId)
            ->orderByDesc('week_start_date')
            ->limit(max(1, $count))
            ->get();

        return $rows->map(function ($r) {
            return [
                'id' => (int) $r->id,
                'user_id' => (int) $r->user_id,
                'week_start_date' => (string) $r->week_start_date,
                'week_end_date' => (string) $r->week_end_date,
                'block_type' => $r->block_type,
                'week_role' => $r->week_role,
                'block_goal' => $r->block_goal,
                'key_capability_focus' => $r->key_capability_focus,
                'load_direction' => $r->load_direction,
                'planned_total_min' => $r->planned_total_min !== null ? (int) $r->planned_total_min : null,
                'actual_total_min' => $r->actual_total_min !== null ? (int) $r->actual_total_min : null,
                'planned_quality_count' => $r->planned_quality_count !== null ? (int) $r->planned_quality_count : null,
                'actual_quality_count' => $r->actual_quality_count !== null ? (int) $r->actual_quality_count : null,
                'goal_met' => $r->goal_met !== null ? (bool) $r->goal_met : null,
            ];
        })->toArray();
    }

    public function getWeekGoalMet(int $userId, string $weekStartDate): ?bool
    {
        $row = DB::table('training_weeks')
            ->where('user_id', $userId)
            ->where('week_start_date', $weekStartDate)
            ->first(['goal_met']);
        if (!$row || $row->goal_met === null) {
            return null;
        }
        return (bool) $row->goal_met;
    }

    private function resolveWeekStartDate(array $planOutput): CarbonImmutable
    {
        $iso = $planOutput['weekStartIso'] ?? null;
        if (is_string($iso) && $iso !== '') {
            try {
                return CarbonImmutable::parse($iso)->startOfDay();
            } catch (\Throwable) {
                // fallthrough
            }
        }
        return CarbonImmutable::now('UTC')->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    private function resolveWorkoutDt(array $summary, $createdAt): Carbon
    {
        $startTimeIso = $summary['startTimeIso'] ?? null;
        if (is_string($startTimeIso) && $startTimeIso !== '') {
            try {
                return Carbon::parse($startTimeIso)->utc();
            } catch (\Throwable) {
                // fallback
            }
        }
        return Carbon::parse($createdAt)->utc();
    }

    private function extractDurationSec(array $summary): float
    {
        $candidates = [
            $summary['trimmed']['durationSec'] ?? null,
            $summary['original']['durationSec'] ?? null,
            $summary['durationSec'] ?? null,
        ];
        foreach ($candidates as $v) {
            if (is_numeric($v) && (float) $v > 0) {
                return (float) $v;
            }
        }
        return 0.0;
    }

    /**
     * Jakościowy workout = znaczący udział Z3+ LUB kind=quality/interval/threshold.
     */
    private function looksLikeQualityWorkout(array $summary): bool
    {
        $kindTags = [];
        foreach (['sport', 'kind', 'type', 'workoutType'] as $k) {
            $v = $summary[$k] ?? null;
            if (is_string($v) && $v !== '') {
                $kindTags[] = strtolower($v);
            }
        }
        foreach ($kindTags as $tag) {
            if (preg_match('/(quality|interval|threshold|tempo|vo2|fartlek)/', $tag)) {
                return true;
            }
        }

        // Heurystyka po bucketach: Z3+ stanowi > 20% całkowitego czasu.
        $buckets = $summary['buckets'] ?? $summary['trimmed']['buckets'] ?? null;
        if (is_array($buckets)) {
            $total = (float) ($buckets['totalSec'] ?? 0);
            if ($total > 0) {
                $high = (float) ($buckets['z3Sec'] ?? 0)
                    + (float) ($buckets['z4Sec'] ?? 0)
                    + (float) ($buckets['z5Sec'] ?? 0);
                if ($total > 0 && ($high / $total) >= 0.20) {
                    return true;
                }
            }
        }

        return false;
    }
}
