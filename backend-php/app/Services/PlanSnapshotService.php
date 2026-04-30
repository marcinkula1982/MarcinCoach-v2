<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PlanSnapshotService
{
    private const DAY_OFFSETS = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];

    /**
     * Build and persist a plan-snapshot-v2 from a raw WeeklyPlanService output.
     *
     * Validation rules (all must pass; otherwise returns silently without writing):
     *  - $plan must have a non-empty weekStartIso and weekEndIso
     *  - At least one session must be mappable to a dateIso
     *  - $source must be 'weekly' or 'rolling'
     *
     * @param array<string,mixed> $plan   Raw output of WeeklyPlanService::generatePlan()
     * @param string              $source 'weekly' | 'rolling'
     */
    public function saveFromPlan(int $userId, array $plan, string $source): void
    {
        $weekStartIso = is_string($plan['weekStartIso'] ?? null) ? $plan['weekStartIso'] : null;
        $weekEndIso   = is_string($plan['weekEndIso']   ?? null) ? $plan['weekEndIso']   : null;

        if ($weekStartIso === null || $weekStartIso === '' || $weekEndIso === null || $weekEndIso === '') {
            return;
        }

        try {
            $weekStart = CarbonImmutable::parse($weekStartIso)->startOfDay();
        } catch (\Throwable) {
            return;
        }

        $rawSessions = is_array($plan['sessions'] ?? null) ? $plan['sessions'] : [];
        $sessions = [];

        foreach ($rawSessions as $s) {
            if (!is_array($s)) {
                continue;
            }

            // Prefer explicit dateIso (rolling plan after publicWeek transformation)
            $dateIso = (is_string($s['dateIso'] ?? null) && $s['dateIso'] !== '') ? $s['dateIso'] : null;

            // Fall back to computing from the 'day' code
            if ($dateIso === null) {
                $day = is_string($s['day'] ?? null) ? strtolower(trim($s['day'])) : null;
                if ($day === null || !array_key_exists($day, self::DAY_OFFSETS)) {
                    continue;
                }
                $dateIso = $weekStart->addDays(self::DAY_OFFSETS[$day])->toDateString();
            }

            $sessions[] = [
                'dateIso'       => $dateIso,
                'type'          => is_string($s['type'] ?? null) ? $s['type'] : null,
                'durationMin'   => is_numeric($s['durationMin'] ?? null) ? (int) $s['durationMin'] : null,
                'intensityHint' => (is_string($s['intensityHint'] ?? null) && $s['intensityHint'] !== '') ? $s['intensityHint'] : null,
                'structure'     => $s['structure'] ?? null,
                'blocks'        => $s['blocks'] ?? null,
            ];
        }

        if (empty($sessions)) {
            return;
        }

        $blockContext = is_array($plan['blockContext'] ?? null) ? $plan['blockContext'] : [];

        $snapshot = [
            'version'        => 'plan-snapshot-v2',
            'source'         => $source,
            'window'         => [
                'startIso' => $weekStartIso,
                'endIso'   => $weekEndIso,
            ],
            'sessions'       => $sessions,
            // Flat aliases consumed by saveForUser() → DB columns
            'windowStartIso' => $weekStartIso,
            'windowEndIso'   => $weekEndIso,
            'blockContext'   => $blockContext,
        ];

        $this->saveForUser($userId, $snapshot);
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    public function saveForUser(int $userId, array $snapshot): void
    {
        $blockContext = is_array($snapshot['blockContext'] ?? null) ? $snapshot['blockContext'] : [];

        DB::table('plan_snapshots')->insert([
            'user_id' => $userId,
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'window_start_iso' => (string) ($snapshot['windowStartIso'] ?? ''),
            'window_end_iso' => (string) ($snapshot['windowEndIso'] ?? ''),
            'block_type' => $this->nullableStr($blockContext['block_type'] ?? null),
            'block_goal' => $this->nullableStr($blockContext['block_goal'] ?? null),
            'week_role' => $this->nullableStr($blockContext['week_role'] ?? null),
            'load_direction' => $this->nullableStr($blockContext['load_direction'] ?? null),
            'key_capability_focus' => $this->nullableStr($blockContext['key_capability_focus'] ?? null),
            'created_at' => now(),
        ]);
    }

    private function nullableStr(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;
        return $s === '' ? null : $s;
    }
}
