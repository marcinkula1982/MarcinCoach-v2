<?php

namespace App\Services;

use App\Models\Workout;
use App\Models\WorkoutImportEvent;
use App\Support\WorkoutSummaryBuilder;

class ExternalWorkoutImportService
{
    /**
     * @param array<int,array<string,mixed>> $activities
     * @return array{fetched:int,imported:int,deduped:int,failed:int}
     */
    public function importActivities(int $userId, string $provider, array $activities): array
    {
        $imported = 0;
        $deduped = 0;
        $failed = 0;

        foreach ($activities as $activity) {
            $sourceActivityId = isset($activity['sourceActivityId']) ? (string) $activity['sourceActivityId'] : null;
            $startTimeIso = (string) ($activity['startTimeIso'] ?? '');
            $durationSec = (int) ($activity['durationSec'] ?? 0);
            $distanceM = (int) ($activity['distanceM'] ?? 0);
            if ($sourceActivityId === null || $sourceActivityId === '' || $startTimeIso === '' || $durationSec <= 0 || $distanceM < 0) {
                $failed++;
                continue;
            }

            $existing = Workout::query()
                ->where('user_id', $userId)
                ->where('source', $provider)
                ->where('source_activity_id', $sourceActivityId)
                ->first();

            if ($existing) {
                $deduped++;
                WorkoutImportEvent::create([
                    'workout_id' => $existing->id,
                    'source' => $provider,
                    'source_activity_id' => $sourceActivityId,
                    'tcx_hash' => null,
                    'status' => 'DEDUPED',
                    'imported_at' => now(),
                ]);
                continue;
            }

            $summary = WorkoutSummaryBuilder::build($startTimeIso, $durationSec, $distanceM, ['provider' => $provider]);
            $dedupeKey = sprintf('%s:%s', strtolower($provider), $sourceActivityId);

            $workout = Workout::create([
                'user_id' => $userId,
                'action' => 'import',
                'kind' => 'training',
                'summary' => $summary,
                'source' => $provider,
                'source_activity_id' => $sourceActivityId,
                'dedupe_key' => $dedupeKey,
            ]);
            WorkoutImportEvent::create([
                'workout_id' => $workout->id,
                'source' => $provider,
                'source_activity_id' => $sourceActivityId,
                'tcx_hash' => null,
                'status' => 'CREATED',
                'imported_at' => now(),
            ]);
            $imported++;
        }

        return [
            'fetched' => count($activities),
            'imported' => $imported,
            'deduped' => $deduped,
            'failed' => $failed,
        ];
    }
}
