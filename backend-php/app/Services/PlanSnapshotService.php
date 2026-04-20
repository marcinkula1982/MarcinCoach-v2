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
        DB::table('plan_snapshots')->insert([
            'user_id' => $userId,
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'window_start_iso' => (string) ($snapshot['windowStartIso'] ?? ''),
            'window_end_iso' => (string) ($snapshot['windowEndIso'] ?? ''),
            'created_at' => now(),
        ]);
    }
}
