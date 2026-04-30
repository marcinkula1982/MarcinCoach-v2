<?php

namespace App\Services;

use App\Models\ManualCheckIn;
use App\Models\UserProfile;
use App\Models\Workout;
use App\Support\WorkoutSourceContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ManualCheckInService
{
    /**
     * @param array<string,mixed> $payload
     * @return array{body:array<string,mixed>,status:int}
     */
    public function upsert(int $userId, array $payload): array
    {
        $date = $this->normalizeDate((string) $payload['plannedSessionDate']);
        $plannedSessionId = $this->nullableString($payload['plannedSessionId'] ?? null);
        $status = $this->normalizeStatus((string) $payload['status']);
        $checkinKey = $this->buildCheckinKey($date, $plannedSessionId);

        return DB::transaction(function () use ($userId, $payload, $date, $plannedSessionId, $status, $checkinKey): array {
            $existing = ManualCheckIn::query()
                ->where('user_id', $userId)
                ->where('checkin_key', $checkinKey)
                ->first();
            $created = $existing === null;

            $plannedSession = $this->plannedSessionPayload($payload);
            $actualDurationMin = $this->intOrNull($payload['actualDurationMin'] ?? $payload['durationMin'] ?? null);
            $distanceM = $this->distanceM($payload['distanceM'] ?? null, $payload['distanceKm'] ?? null);
            $rpe = $this->intOrNull($payload['rpe'] ?? null);
            $painFlag = $this->painFlag($payload);
            $plannedDurationMin = $this->intOrNull($payload['plannedDurationMin'] ?? data_get($plannedSession, 'durationMin'));
            $plannedType = $this->nullableString($payload['plannedType'] ?? data_get($plannedSession, 'type'));
            $plannedIntensity = $this->nullableString($payload['plannedIntensity'] ?? data_get($plannedSession, 'intensityHint'));

            $checkIn = $existing ?? new ManualCheckIn([
                'user_id' => $userId,
                'checkin_key' => $checkinKey,
            ]);

            $checkIn->fill([
                'planned_session_date' => $date,
                'planned_session_id' => $plannedSessionId,
                'status' => $status,
                'plan_compliance' => $this->planComplianceForStatus($status),
                'planned_type' => $plannedType,
                'planned_duration_min' => $plannedDurationMin,
                'planned_intensity' => $plannedIntensity,
                'planned_payload' => $plannedSession,
                'actual_duration_min' => $actualDurationMin,
                'distance_m' => $distanceM,
                'rpe' => $rpe,
                'mood' => $this->nullableString($payload['mood'] ?? null),
                'pain_flag' => $painFlag,
                'pain_note' => $this->nullableString($payload['painNote'] ?? $payload['painDescription'] ?? null),
                'note' => $this->nullableString($payload['note'] ?? null),
                'skip_reason' => $this->nullableString($payload['skipReason'] ?? $payload['reason'] ?? null),
                'modification_reason' => $this->nullableString($payload['modificationReason'] ?? null),
                'plan_modifications' => is_array($payload['planModifications'] ?? null) ? $payload['planModifications'] : null,
            ]);
            $checkIn->save();

            if ($painFlag) {
                $this->markPainOnProfile($userId, $checkIn);
            }

            if ($status === 'skipped') {
                $this->deleteSyntheticWorkout($checkIn);
                $checkIn->workout_id = null;
                $checkIn->save();
            } else {
                $workout = $this->upsertSyntheticWorkout($checkIn, $payload);
                $checkIn->workout_id = $workout->id;
                $checkIn->save();
                $this->syncDerivedWorkoutData($workout, $checkIn);
            }

            $checkIn->refresh();

            return [
                'body' => [
                    'created' => $created,
                    'updated' => ! $created,
                    'checkIn' => $this->publicCheckIn($checkIn),
                ],
                'status' => $created ? 201 : 200,
            ];
        });
    }

    private function upsertSyntheticWorkout(ManualCheckIn $checkIn, array $payload): Workout
    {
        $sourceActivityId = $checkIn->checkin_key;
        $existing = null;
        if ($checkIn->workout_id !== null) {
            $existing = Workout::query()
                ->where('id', $checkIn->workout_id)
                ->where('user_id', $checkIn->user_id)
                ->first();
        }
        if (! $existing) {
            $existing = Workout::query()
                ->where('user_id', $checkIn->user_id)
                ->where('source', WorkoutSourceContract::MANUAL_CHECK_IN)
                ->where('source_activity_id', $sourceActivityId)
                ->first();
        }

        $summary = $this->buildWorkoutSummary($checkIn, $payload);
        $meta = $this->buildWorkoutMeta($checkIn);
        $dedupeKey = WorkoutSourceContract::buildDedupeKey(
            WorkoutSourceContract::MANUAL_CHECK_IN,
            $sourceActivityId,
            (string) $summary['startTimeIso'],
            (int) ($summary['durationSec'] ?? 1),
            (int) ($summary['distanceM'] ?? 0),
        );

        $workout = $existing ?? new Workout([
            'user_id' => $checkIn->user_id,
            'source' => WorkoutSourceContract::MANUAL_CHECK_IN,
            'source_activity_id' => $sourceActivityId,
        ]);

        $workout->fill([
            'action' => 'save',
            'kind' => 'training',
            'summary' => $summary,
            'workout_meta' => $meta,
            'race_meta' => null,
            'source' => WorkoutSourceContract::MANUAL_CHECK_IN,
            'source_activity_id' => $sourceActivityId,
            'dedupe_key' => $dedupeKey,
        ]);
        $workout->save();

        return $workout;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function buildWorkoutSummary(ManualCheckIn $checkIn, array $payload): array
    {
        $startTimeIso = $this->actualStartIso($checkIn, $payload);
        $durationSec = $checkIn->actual_duration_min !== null ? $checkIn->actual_duration_min * 60 : null;
        $distanceM = $checkIn->distance_m;
        $sport = $this->nullableString($payload['sport'] ?? data_get($checkIn->planned_payload, 'sportKind')) ?? 'run';

        $summary = [
            'startTimeIso' => $startTimeIso,
            'sport' => $sport,
            'activityType' => 'manual_check_in',
            'manualCheckIn' => true,
            'manualCheckInStatus' => $checkIn->status,
            'plannedSessionDate' => $checkIn->planned_session_date?->toDateString(),
            'plannedSession' => $checkIn->planned_payload,
            'dataAvailability' => [
                'gps' => false,
                'hr' => false,
                'cadence' => false,
                'power' => false,
                'elevation' => false,
                'movingTime' => $durationSec !== null,
            ],
        ];

        if ($durationSec !== null) {
            $summary['durationSec'] = $durationSec;
            $summary['movingTimeSec'] = $durationSec;
            $summary['elapsedTimeSec'] = $durationSec;
            $summary['original']['durationSec'] = $durationSec;
            $summary['trimmed']['durationSec'] = $durationSec;
        }
        if ($distanceM !== null) {
            $summary['distanceM'] = $distanceM;
            $summary['original']['distanceM'] = $distanceM;
            $summary['trimmed']['distanceM'] = $distanceM;
        }
        if ($durationSec !== null && $distanceM !== null && $distanceM > 0) {
            $summary['avgPaceSecPerKm'] = (int) round($durationSec / ($distanceM / 1000));
        }
        if ($checkIn->rpe !== null) {
            $summary['perceivedEffort'] = $checkIn->rpe;
            $summary['intensityLevel'] = $this->intensityFromRpe($checkIn->rpe);
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildWorkoutMeta(ManualCheckIn $checkIn): array
    {
        return [
            'manualCheckInId' => (int) $checkIn->id,
            'manualCheckInStatus' => $checkIn->status,
            'planCompliance' => $checkIn->plan_compliance,
            'plannedSessionDate' => $checkIn->planned_session_date?->toDateString(),
            'plannedSessionId' => $checkIn->planned_session_id,
            'plannedSession' => $checkIn->planned_payload,
            'plannedDurationMin' => $checkIn->planned_duration_min,
            'actualDurationMin' => $checkIn->actual_duration_min,
            'distanceM' => $checkIn->distance_m,
            'rpe' => $checkIn->rpe,
            'perceivedEffort' => $checkIn->rpe,
            'mood' => $checkIn->mood,
            'painFlag' => (bool) $checkIn->pain_flag,
            'painNote' => $checkIn->pain_note,
            'note' => $checkIn->note,
            'modificationReason' => $checkIn->modification_reason,
            'planModifications' => $checkIn->plan_modifications,
            'dataSource' => 'manual_check_in',
            'hasGps' => false,
        ];
    }

    private function syncDerivedWorkoutData(Workout $workout, ManualCheckIn $checkIn): void
    {
        app(TrainingSignalsService::class)->upsertForWorkout((int) $workout->id);
        $this->upsertManualPlanCompliance((int) $workout->id, $checkIn);
        app(TrainingAlertsV1Service::class)->upsertForWorkout((int) $workout->id);

        $weekStart = CarbonImmutable::parse($checkIn->planned_session_date)->startOfWeek()->toDateString();
        app(PlanMemoryService::class)->updateWeekActuals((int) $checkIn->user_id, $weekStart);
    }

    private function upsertManualPlanCompliance(int $workoutId, ManualCheckIn $checkIn): void
    {
        $actualDurationSec = $checkIn->actual_duration_min !== null ? $checkIn->actual_duration_min * 60 : null;
        $expectedDurationSec = $checkIn->planned_duration_min !== null ? $checkIn->planned_duration_min * 60 : null;
        $deltaDurationSec = null;
        $durationRatio = null;
        $status = 'UNKNOWN';
        $overshoot = false;
        $undershoot = false;

        if ($actualDurationSec !== null && $expectedDurationSec !== null && $expectedDurationSec > 0) {
            $deltaDurationSec = $actualDurationSec - $expectedDurationSec;
            $durationRatio = $actualDurationSec / $expectedDurationSec;
            $status = $this->durationStatus($durationRatio);
            $overshoot = $durationRatio > 1.0;
            $undershoot = $durationRatio < 1.0;
        }

        DB::table('plan_compliance_v1')->updateOrInsert(
            ['workout_id' => $workoutId],
            [
                'expected_duration_sec' => $expectedDurationSec,
                'actual_duration_sec' => $actualDurationSec ?? 0,
                'delta_duration_sec' => $deltaDurationSec,
                'duration_ratio' => $durationRatio,
                'status' => $status,
                'flag_overshoot_duration' => $overshoot,
                'flag_undershoot_duration' => $undershoot,
                'generated_at' => now(),
            ],
        );
    }

    private function deleteSyntheticWorkout(ManualCheckIn $checkIn): void
    {
        if ($checkIn->workout_id === null) {
            return;
        }

        Workout::query()
            ->where('id', $checkIn->workout_id)
            ->where('user_id', $checkIn->user_id)
            ->where('source', WorkoutSourceContract::MANUAL_CHECK_IN)
            ->delete();
    }

    private function markPainOnProfile(int $userId, ManualCheckIn $checkIn): void
    {
        $profile = UserProfile::query()->firstOrCreate(['user_id' => $userId]);
        $health = is_array($profile->health_json) ? $profile->health_json : [];
        $health['hasCurrentPain'] = true;
        $health['latestManualCheckInPainAt'] = now()->utc()->toIso8601String();
        if ($checkIn->pain_note !== null) {
            $health['latestManualCheckInPainNote'] = $checkIn->pain_note;
        }
        $profile->health_json = $health;
        $profile->has_current_pain = true;
        $profile->save();
    }

    private function actualStartIso(ManualCheckIn $checkIn, array $payload): string
    {
        $raw = $this->nullableString($payload['actualStartTimeIso'] ?? null);
        if ($raw !== null) {
            try {
                return CarbonImmutable::parse($raw)->utc()->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable) {
            }
        }

        return CarbonImmutable::parse($checkIn->planned_session_date?->toDateString().' 12:00:00', 'UTC')
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function normalizeDate(string $raw): string
    {
        return CarbonImmutable::parse($raw)->toDateString();
    }

    private function normalizeStatus(string $raw): string
    {
        $status = strtolower(trim($raw));
        return $status === 'completed' ? 'done' : $status;
    }

    private function buildCheckinKey(string $date, ?string $plannedSessionId): string
    {
        if ($plannedSessionId !== null) {
            return 'session:'.sha1($date.'|'.$plannedSessionId);
        }

        return 'date:'.$date;
    }

    private function planComplianceForStatus(string $status): string
    {
        return match ($status) {
            'modified' => 'modified',
            'skipped' => 'skipped',
            default => 'planned',
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function plannedSessionPayload(array $payload): ?array
    {
        $planned = is_array($payload['plannedSession'] ?? null) ? $payload['plannedSession'] : [];
        foreach ([
            'type' => 'plannedType',
            'durationMin' => 'plannedDurationMin',
            'intensityHint' => 'plannedIntensity',
            'dateIso' => 'plannedSessionDate',
        ] as $target => $source) {
            if (! array_key_exists($target, $planned) && array_key_exists($source, $payload)) {
                $planned[$target] = $payload[$source];
            }
        }

        return $planned === [] ? null : $planned;
    }

    private function painFlag(array $payload): bool
    {
        if (array_key_exists('painFlag', $payload)) {
            return (bool) $payload['painFlag'];
        }
        $reason = strtolower((string) ($payload['skipReason'] ?? $payload['reason'] ?? ''));

        return str_contains($reason, 'pain')
            || str_contains($reason, 'injury')
            || str_contains($reason, 'bol')
            || str_contains($reason, 'kontuz');
    }

    private function intOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function distanceM(mixed $distanceM, mixed $distanceKm): ?int
    {
        if (is_numeric($distanceM) && (float) $distanceM > 0) {
            return (int) round((float) $distanceM);
        }
        if (is_numeric($distanceKm) && (float) $distanceKm > 0) {
            return (int) round((float) $distanceKm * 1000);
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function intensityFromRpe(int $rpe): string
    {
        if ($rpe <= 3) {
            return 'easy';
        }
        if ($rpe >= 7) {
            return 'hard';
        }

        return 'moderate';
    }

    private function durationStatus(float $ratio): string
    {
        if ($ratio >= 0.85 && $ratio <= 1.15) {
            return 'OK';
        }
        if (($ratio >= 0.70 && $ratio < 0.85) || ($ratio > 1.15 && $ratio <= 1.30)) {
            return 'MINOR_DEVIATION';
        }

        return 'MAJOR_DEVIATION';
    }

    /**
     * @return array<string,mixed>
     */
    private function publicCheckIn(ManualCheckIn $checkIn): array
    {
        return [
            'id' => (int) $checkIn->id,
            'workoutId' => $checkIn->workout_id !== null ? (int) $checkIn->workout_id : null,
            'plannedSessionDate' => $checkIn->planned_session_date?->toDateString(),
            'plannedSessionId' => $checkIn->planned_session_id,
            'status' => $checkIn->status,
            'planCompliance' => $checkIn->plan_compliance,
            'plannedType' => $checkIn->planned_type,
            'plannedDurationMin' => $checkIn->planned_duration_min,
            'plannedIntensity' => $checkIn->planned_intensity,
            'plannedSession' => $checkIn->planned_payload,
            'actualDurationMin' => $checkIn->actual_duration_min,
            'distanceM' => $checkIn->distance_m,
            'distanceKm' => $checkIn->distance_m !== null ? round($checkIn->distance_m / 1000, 3) : null,
            'rpe' => $checkIn->rpe,
            'mood' => $checkIn->mood,
            'painFlag' => (bool) $checkIn->pain_flag,
            'painNote' => $checkIn->pain_note,
            'note' => $checkIn->note,
            'skipReason' => $checkIn->skip_reason,
            'modificationReason' => $checkIn->modification_reason,
            'planModifications' => $checkIn->plan_modifications,
            'createdAt' => $checkIn->created_at?->toIso8601String(),
            'updatedAt' => $checkIn->updated_at?->toIso8601String(),
        ];
    }
}
