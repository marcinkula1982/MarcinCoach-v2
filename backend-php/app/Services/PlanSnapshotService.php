<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PlanSnapshotService
{
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
