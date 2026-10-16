<?php

namespace App\Services;

use App\Models\UserProfile;
use App\Models\Workout;
use App\Models\WorkoutRawTcx;
use App\Services\Analysis\HrZoneResolver;
use App\Support\WorkoutSummaryBuilder;
use Illuminate\Support\Facades\DB;

/** Shared TCX enrichment, independent of the provider transporting the file. */
class TcxWorkoutEnrichmentService
{
    public function enrich(Workout $workout, string $xml, ?string $name = null): void
    {
        $resolved = app(HrZoneResolver::class)->resolve(UserProfile::where('user_id', $workout->user_id)->first(), null);
        $zones = [];
        foreach ($resolved->zones ?? [] as $zone) {
            $zones[strtolower($zone['name'])] = ['min' => $zone['minBpm'], 'max' => $zone['maxBpm']];
        }
        $parsed = app(TcxParsingService::class)->parse($xml, $zones ?: null);

        // Reject mismatched files rather than attaching another activity's samples.
        $summary = $workout->summary ?? [];
        $start = strtotime((string) ($summary['startTimeIso'] ?? ''));
        if ($start === false || abs(strtotime($parsed['startTimeIso']) - $start) > 300) {
            throw new \InvalidArgumentException('TCX activity start mismatch');
        }
        $enriched = WorkoutSummaryBuilder::build(
            $parsed['startTimeIso'], $parsed['durationSec'], $parsed['distanceM'], parsed: $parsed,
        );
        // Preserve imported totals and user edits; add sample-derived fields.
        foreach (['startTimeIso', 'durationSec', 'distanceM', 'original', 'trimmed', 'sport'] as $key) {
            unset($enriched[$key]);
        }
        if ($parsed['hrSampleCount'] === 0 && isset($summary['hr']['avgBpm'])) {
            // A file without HR samples must not erase the provider's known average.
            unset($enriched['hr']);
            $enriched['dataAvailability']['hr'] = true;
        }
        if ($name !== null && $name !== '') {
            $enriched['name'] = $name;
        }
        $enriched['tcxEnrichmentVersion'] = 1;
        $enriched['tcxHash'] = hash('sha256', $xml);

        DB::transaction(function () use ($workout, $xml, $summary, $enriched) {
            $workout->summary = array_replace($summary, $enriched);
            $workout->save();
            WorkoutRawTcx::updateOrCreate(['workout_id' => $workout->id], ['xml' => $xml, 'created_at' => now()]);
            foreach ([TrainingSignalsService::class, TrainingSignalsV2Service::class,
                PlanComplianceService::class, PlanComplianceV2Service::class, TrainingAlertsV1Service::class] as $service) {
                app($service)->upsertForWorkout($workout->id);
            }
        });
    }
}
