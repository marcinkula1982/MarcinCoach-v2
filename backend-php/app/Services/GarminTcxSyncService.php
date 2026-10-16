<?php

namespace App\Services;

use App\Models\Workout;
use Illuminate\Support\Facades\Log;

class GarminTcxSyncService
{
    // Bound work in the HTTP request; further syncs pick up missing files.
    private const MAX_DOWNLOADS = 5;
    private const BUDGET_SECONDS = 20;

    public function __construct(
        private readonly GarminConnectorService $connector,
        private readonly TcxWorkoutEnrichmentService $enrichment,
    ) {}

    public function sync(int $userId, array $activities): array
    {
        $counts = ['tcxEnriched' => 0, 'tcxFailed' => 0, 'tcxPending' => 0];
        $attempts = 0;
        $started = microtime(true);
        $seen = [];
        foreach ($activities as $activity) {
            $activityId = (string) ($activity['sourceActivityId'] ?? '');
            if ($activityId === '' || isset($seen[$activityId])) {
                continue;
            }
            $seen[$activityId] = true;
            // Only enrich the matching provider record, never a cross-source dedupe match.
            $workout = Workout::where('user_id', $userId)->where('source', 'GARMIN')
                ->where('source_activity_id', $activityId)->first();
            if (!$workout || (($workout->summary['tcxEnrichmentVersion'] ?? null) === 1 && $workout->rawTcx()->exists())) {
                continue;
            }
            if ($attempts >= self::MAX_DOWNLOADS || microtime(true) - $started >= self::BUDGET_SECONDS) {
                $counts['tcxPending']++;
                continue;
            }
            $attempts++;
            try {
                $file = $this->connector->downloadTcx($userId, $activityId);
                if (!$file['ok']) {
                    $counts['tcxFailed']++;
                    Log::warning('Garmin TCX download failed', [
                        'workoutId' => $workout->id, 'error' => $file['error'],
                        'upstreamStatus' => $file['upstreamStatus'] ?? null,
                    ]);
                    continue;
                }
                $this->enrichment->enrich($workout, $file['xml'], isset($activity['name']) ? (string) $activity['name'] : null);
                $counts['tcxEnriched']++;
            } catch (\Throwable $e) {
                $counts['tcxFailed']++;
                // Exception messages can contain XML, SQL bindings or upstream secrets.
                Log::warning('Garmin TCX enrichment failed', ['workoutId' => $workout->id, 'exceptionClass' => $e::class]);
            }
        }

        return $counts;
    }
}
