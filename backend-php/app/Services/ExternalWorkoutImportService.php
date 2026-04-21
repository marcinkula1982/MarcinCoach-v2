<?php

namespace App\Services;

use App\Models\Workout;
use App\Models\WorkoutImportEvent;
use App\Support\WorkoutSourceContract;
use App\Support\WorkoutSummaryBuilder;

class ExternalWorkoutImportService
{
    public function __construct(
        private readonly ?TrainingSignalsService $trainingSignalsService = null,
        private readonly ?PlanComplianceService $planComplianceService = null,
        private readonly ?TrainingAlertsV1Service $trainingAlertsV1Service = null,
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $activities
     * @return array{fetched:int,imported:int,deduped:int,failed:int}
     */
    public function importActivities(int $userId, string $provider, array $activities): array
    {
        $imported = 0;
        $deduped = 0;
        $failed = 0;

        // Normalize provider once; all storage and event records use the canonical value.
        $canonicalSource = WorkoutSourceContract::normalize($provider);

        foreach ($activities as $activity) {
            $sourceActivityId = WorkoutSourceContract::normalizeActivityId(
                isset($activity['sourceActivityId']) ? (string) $activity['sourceActivityId'] : null
            );
            $startTimeIso = (string) ($activity['startTimeIso'] ?? '');
            $durationSec = (int) ($activity['durationSec'] ?? 0);
            $distanceM = (int) ($activity['distanceM'] ?? 0);
            if ($sourceActivityId === null || $startTimeIso === '' || $durationSec <= 0 || $distanceM < 0) {
                $failed++;
                continue;
            }

            $existing = Workout::query()
                ->where('user_id', $userId)
                ->where('source', $canonicalSource)
                ->where('source_activity_id', $sourceActivityId)
                ->first();

            if ($existing) {
                $deduped++;
                WorkoutImportEvent::create([
                    'workout_id' => $existing->id,
                    'source' => $canonicalSource,
                    'source_activity_id' => $sourceActivityId,
                    'tcx_hash' => null,
                    'status' => 'DEDUPED',
                    'imported_at' => now(),
                ]);
                continue;
            }

            $summary = WorkoutSummaryBuilder::build($startTimeIso, $durationSec, $distanceM, ['provider' => $canonicalSource]);
            $dedupeKey = WorkoutSourceContract::buildDedupeKey($canonicalSource, $sourceActivityId, $startTimeIso, $durationSec, $distanceM);

            $workout = Workout::create([
                'user_id' => $userId,
                'action' => 'import',
                'kind' => 'training',
                'summary' => $summary,
                'source' => $canonicalSource,
                'source_activity_id' => $sourceActivityId,
                'dedupe_key' => $dedupeKey,
            ]);
            WorkoutImportEvent::create([
                'workout_id' => $workout->id,
                'source' => $canonicalSource,
                'source_activity_id' => $sourceActivityId,
                'tcx_hash' => null,
                'status' => 'CREATED',
                'imported_at' => now(),
            ]);

            // Run signal pipelines so Strava/Garmin imports hydrate the same downstream
            // tables as TCX imports. V2 signals and plan_compliance_v2 require raw TCX,
            // which is not available here — those stay TCX-only on purpose.
            $this->runSignalPipelines($workout->id);
            $imported++;
        }

        return [
            'fetched' => count($activities),
            'imported' => $imported,
            'deduped' => $deduped,
            'failed' => $failed,
        ];
    }

    private function runSignalPipelines(int $workoutId): void
    {
        try {
            ($this->trainingSignalsService ?? new TrainingSignalsService())->upsertForWorkout($workoutId);
            ($this->planComplianceService ?? new PlanComplianceService())->upsertForWorkout($workoutId);
            ($this->trainingAlertsV1Service ?? new TrainingAlertsV1Service())->upsertForWorkout($workoutId);
        } catch (\Throwable $e) {
            // Pipelines must not break import; log and continue so the import metric stays accurate.
            \Log::warning('ExternalWorkoutImportService signal pipeline failed', [
                'workoutId' => $workoutId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
