<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workout;
use App\Models\WorkoutRawTcx;
use App\Models\WorkoutImportEvent;
use App\Services\PlanComplianceService;
use App\Support\WorkoutSourceContract;
use App\Support\WorkoutSummaryBuilder;
use App\Services\PlanComplianceV2Service;
use App\Services\TcxParsingService;
use App\Services\TrainingAlertsV1Service;
use App\Services\TrainingSignalsService;
use App\Services\TrainingSignalsV2Service;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkoutsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $rows = Workout::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();

        $result = $rows->map(function (Workout $w) use ($userId) {
            return [
                'id' => $w->id,
                'userId' => $userId,
                'action' => $w->action,
                'kind' => $w->kind,
                'summary' => is_array($w->summary) ? $w->summary : [],
                'raceMeta' => is_array($w->race_meta) ? $w->race_meta : null,
                'workoutMeta' => is_array($w->workout_meta) ? $w->workout_meta : null,
                'createdAt' => $w->created_at?->toIso8601String(),
            ];
        })->values();

        return response()->json($result);
    }

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tcxRaw' => ['required', 'string', 'min:1'],
            'action' => ['required', Rule::in(['preview-only', 'save'])],
            'kind' => ['required', Rule::in(['training', 'race'])],
            'summary' => ['required', 'array'],
            'raceMeta' => ['sometimes', 'nullable', 'array'],
            'workoutMeta' => ['sometimes', 'nullable', 'array'],
        ]);

        $userId = $this->authUserId($request);
        $summary = is_array($validated['summary']) ? $validated['summary'] : [];
        $startTimeIso = (string) ($summary['startTimeIso'] ?? now()->toIso8601String());
        $durationSec = (int) ($summary['trimmed']['durationSec'] ?? $summary['original']['durationSec'] ?? 0);
        $distanceM = (int) ($summary['trimmed']['distanceM'] ?? $summary['original']['distanceM'] ?? 0);

        // Enrich the caller-provided summary with parser-derived fields (sport, HR, pace,
        // intensity buckets) when the raw TCX is valid. Caller-supplied keys always win.
        $parsedBlob = $this->tryParseRichBlob((string) $validated['tcxRaw'], $userId);
        if ($parsedBlob !== null) {
            $startTimeIso = (string) ($summary['startTimeIso'] ?? $parsedBlob['startTimeIso']);
            if ($durationSec <= 0) {
                $durationSec = (int) $parsedBlob['durationSec'];
            }
            if ($distanceM <= 0) {
                $distanceM = (int) $parsedBlob['distanceM'];
            }
            $enriched = WorkoutSummaryBuilder::build(
                $startTimeIso,
                max($durationSec, 0),
                max($distanceM, 0),
                parsed: $parsedBlob,
            );
            // Strip structural keys rebuilt below so caller summary can layer on top.
            unset($enriched['startTimeIso'], $enriched['durationSec'], $enriched['distanceM'], $enriched['original'], $enriched['trimmed']);
            $summary = array_merge($enriched, $summary);
        }
        $dedupeKey = $this->generateDedupeKey(WorkoutSourceContract::MANUAL_UPLOAD, null, $startTimeIso, max($durationSec, 1), max($distanceM, 0));

        $workout = Workout::create([
            'user_id' => $userId,
            'action' => (string) $validated['action'],
            'kind' => (string) $validated['kind'],
            'summary' => $summary,
            'race_meta' => $validated['raceMeta'] ?? null,
            'workout_meta' => $validated['workoutMeta'] ?? null,
            'source' => WorkoutSourceContract::MANUAL_UPLOAD,
            'source_activity_id' => null,
            'dedupe_key' => $dedupeKey,
        ]);

        WorkoutRawTcx::updateOrCreate(
            ['workout_id' => $workout->id],
            ['xml' => (string) $validated['tcxRaw'], 'created_at' => now()],
        );

        return response()->json([
            'id' => $workout->id,
            'userId' => $userId,
            'action' => $workout->action,
            'kind' => $workout->kind,
            'summary' => $workout->summary ?? [],
            'raceMeta' => $workout->race_meta,
            'workoutMeta' => $workout->workout_meta,
            'createdAt' => $workout->created_at?->toIso8601String(),
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $rows = Workout::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get(['id', 'summary', 'workout_meta', 'created_at']);

        $result = $rows->map(fn (Workout $w) => $this->buildAnalyticsRow($w))->filter()->values();
        return response()->json($result)->header('Cache-Control', 'private, no-cache, must-revalidate');
    }

    public function analyticsRows(Request $request): JsonResponse
    {
        return $this->analytics($request);
    }

    public function analyticsSummary(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $rows = Workout::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get(['id', 'summary', 'workout_meta', 'created_at'])
            ->map(fn (Workout $w) => $this->buildAnalyticsRow($w))
            ->filter()
            ->values();

        $totals = [
            'workouts' => $rows->count(),
            'distanceKm' => round((float) $rows->sum('distanceKm'), 2),
            'durationMin' => round((float) $rows->sum('durationMin'), 2),
        ];

        $aggregateGroup = function ($groupRows): array {
            $zones = ['z1Min' => 0.0, 'z2Min' => 0.0, 'z3Min' => 0.0, 'z4Min' => 0.0, 'z5Min' => 0.0];
            $longRunKm = 0.0;
            $pacedDistanceKm = 0.0;
            $pacedSeconds = 0.0;
            foreach ($groupRows as $row) {
                $zones['z1Min'] += (float) ($row['intensity']['z1Min'] ?? 0);
                $zones['z2Min'] += (float) ($row['intensity']['z2Min'] ?? 0);
                $zones['z3Min'] += (float) ($row['intensity']['z3Min'] ?? 0);
                $zones['z4Min'] += (float) ($row['intensity']['z4Min'] ?? 0);
                $zones['z5Min'] += (float) ($row['intensity']['z5Min'] ?? 0);
                if (($row['type'] ?? null) === 'run') {
                    $km = (float) ($row['distanceKm'] ?? 0);
                    if ($km > $longRunKm) {
                        $longRunKm = $km;
                    }
                    $pace = $row['avgPaceSecPerKm'] ?? null;
                    if (is_numeric($pace) && $pace > 0 && $km > 0) {
                        $pacedDistanceKm += $km;
                        $pacedSeconds += $km * (float) $pace;
                    }
                }
            }
            $avgPaceSecPerKm = $pacedDistanceKm > 0
                ? (int) round($pacedSeconds / $pacedDistanceKm)
                : null;
            return [
                'zones' => [
                    'z1Min' => round($zones['z1Min'], 2),
                    'z2Min' => round($zones['z2Min'], 2),
                    'z3Min' => round($zones['z3Min'], 2),
                    'z4Min' => round($zones['z4Min'], 2),
                    'z5Min' => round($zones['z5Min'], 2),
                ],
                'longRunKm' => round($longRunKm, 2),
                'avgPaceSecPerKm' => $avgPaceSecPerKm,
            ];
        };

        $byDay = $rows
            ->groupBy(function (array $row) {
                return Carbon::parse((string) $row['workoutDt'])->utc()->format('Y-m-d');
            })
            ->map(function ($dayRows, string $day) use ($aggregateGroup) {
                return [
                    'day' => $day,
                    'workouts' => $dayRows->count(),
                    'distanceKm' => round((float) $dayRows->sum('distanceKm'), 2),
                    'durationMin' => round((float) $dayRows->sum('durationMin'), 2),
                ] + $aggregateGroup($dayRows);
            })
            ->values()
            ->sortBy('day')
            ->values()
            ->all();

        $byWeek = $rows
            ->groupBy(function (array $row) {
                $dt = Carbon::parse((string) $row['workoutDt'])->utc();
                return $dt->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
            })
            ->map(function ($weekRows, string $weekStart) use ($aggregateGroup) {
                return [
                    'weekStart' => $weekStart,
                    'workouts' => $weekRows->count(),
                    'distanceKm' => round((float) $weekRows->sum('distanceKm'), 2),
                    'durationMin' => round((float) $weekRows->sum('durationMin'), 2),
                ] + $aggregateGroup($weekRows);
            })
            ->values()
            ->sortBy('weekStart')
            ->values()
            ->all();

        return response()->json([
            'totals' => $totals,
            'byWeek' => $byWeek,
            'byDay' => $byDay,
        ]);
    }

    public function analyticsSummaryV2(Request $request): JsonResponse
    {
        return $this->analyticsSummary($request);
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];
        $rawTcxXml = $file->getContent();
        if (!is_string($rawTcxXml) || trim($rawTcxXml) === '') {
            return response()->json(['message' => 'Brak pliku'], 422);
        }

        try {
            $parsed = $this->parseTcxXml($rawTcxXml);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $importRequest = new Request([
            'source' => 'tcx',
            'sourceActivityId' => null,
            'startTimeIso' => $parsed['startTimeIso'],
            'durationSec' => $parsed['durationSec'],
            'distanceM' => $parsed['distanceM'],
            'rawTcxXml' => $rawTcxXml,
        ]);
        $importRequest->headers->set('x-username', (string) $request->header('x-username', ''));
        $importRequest->headers->set('x-session-token', (string) $request->header('x-session-token', ''));

        return $this->import($importRequest);
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', Rule::in(['garmin', 'tcx', 'manual'])],
            'sourceActivityId' => ['nullable', 'string'],
            'startTimeIso' => ['required', 'date'],
            'durationSec' => ['required', 'integer', 'min:1'],
            'distanceM' => ['required', 'integer', 'min:0'],
            'rawTcxXml' => ['nullable', 'required_if:source,tcx', 'string', 'min:1'],
        ]);

        // Normalize to canonical uppercase values before any lookup or storage.
        // 'tcx' and 'manual' both map to MANUAL_UPLOAD; 'garmin' -> GARMIN; 'strava' -> STRAVA.
        $rawSource = $validated['source'];
        $source = WorkoutSourceContract::normalize($rawSource);
        $sourceActivityId = WorkoutSourceContract::normalizeActivityId($validated['sourceActivityId'] ?? null);
        
        // Parse TCX XML if the incoming source was 'tcx' and rawTcxXml is provided.
        $startTimeIso = $validated['startTimeIso'];
        $durationSec = $validated['durationSec'];
        $distanceM = $validated['distanceM'];

        $userId = $this->authUserId($request);
        $parsedBlob = null;

        if ($rawSource === 'tcx' && !empty($validated['rawTcxXml'])) {
            try {
                $parsedBlob = $this->parseTcxXml($validated['rawTcxXml'], $userId);
                $startTimeIso = $parsedBlob['startTimeIso'];
                $durationSec = $parsedBlob['durationSec'];
                $distanceM = $parsedBlob['distanceM'];
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        // Calculate tcx_hash if rawTcxXml is provided
        $tcxHash = null;
        if (!empty($validated['rawTcxXml'])) {
            $tcxHash = hash('sha256', $validated['rawTcxXml']);
        }

        // Wrap everything in a transaction
        return DB::transaction(function () use ($rawSource, $source, $sourceActivityId, $userId, $startTimeIso, $durationSec, $distanceM, $validated, $tcxHash, $parsedBlob) {
            // Check for existing workout if sourceActivityId is provided
            $existing = null;
            if ($sourceActivityId !== null) {
                $existing = Workout::where('source', $source)
                    ->where('source_activity_id', $sourceActivityId)
                    ->where('user_id', $userId)
                    ->first();

                if ($existing) {
                    // UPSERT: Update existing workout if incoming source was 'tcx' and rawTcxXml is provided.
                    // Use $rawSource (the original boundary value) because $source is already normalized.
                    if ($rawSource === 'tcx' && !empty($validated['rawTcxXml'])) {
                        // Generate dedupe_key with new values
                        $dedupeKey = $this->generateDedupeKey($source, $sourceActivityId, $startTimeIso, $durationSec, $distanceM);

                        // Update summary (enriched with parsed TCX blob when available)
                        $summary = WorkoutSummaryBuilder::build(
                            $startTimeIso,
                            $durationSec,
                            $distanceM,
                            parsed: is_array($parsedBlob) ? $parsedBlob : [],
                        );
                        
                        // Update workout
                        $existing->summary = $summary;
                        $existing->dedupe_key = $dedupeKey;
                        $existing->touch(); // Update updated_at timestamp
                        $existing->save();
                        
                        // Update or create workout_raw_tcx
                        WorkoutRawTcx::updateOrCreate(
                            ['workout_id' => $existing->id],
                            ['xml' => $validated['rawTcxXml'], 'created_at' => now()]
                        );
                        
                        // Log UPDATED event
                        WorkoutImportEvent::create([
                            'workout_id' => $existing->id,
                            'source' => $source,
                            'source_activity_id' => $sourceActivityId,
                            'tcx_hash' => $tcxHash,
                            'status' => 'UPDATED',
                            'imported_at' => now(),
                        ]);
                        
                        // Generate TrainingSignals v1
                        $trainingSignalsService = new TrainingSignalsService();
                        $trainingSignalsService->upsertForWorkout($existing->id);
                        
                        // Generate TrainingSignals v2 (only for TCX with rawTcxXml)
                        $trainingSignalsV2Service = new TrainingSignalsV2Service();
                        $trainingSignalsV2Service->upsertForWorkout($existing->id);
                        
                        // Generate PlanCompliance v2 (only for TCX with rawTcxXml)
                        $planComplianceV2Service = new PlanComplianceV2Service();
                        $planComplianceV2Service->upsertForWorkout($existing->id);
                        
                        // Generate PlanCompliance v1
                        $planComplianceService = new PlanComplianceService();
                        $planComplianceService->upsertForWorkout($existing->id);
                        
                        // Generate TrainingAlerts v1
                        $trainingAlertsV1Service = new TrainingAlertsV1Service();
                        $trainingAlertsV1Service->upsertForWorkout($existing->id);
                        
                        return response()->json([
                            'id' => $existing->id,
                            'created' => false,
                            'updated' => true,
                        ], 200);
                    }
                    
                    // For non-tcx sources, just return existing workout (DEDUPED)
                    // Log DEDUPED event
                    WorkoutImportEvent::create([
                        'workout_id' => $existing->id,
                        'source' => $source,
                        'source_activity_id' => $sourceActivityId,
                        'tcx_hash' => $tcxHash,
                        'status' => 'DEDUPED',
                        'imported_at' => now(),
                    ]);
                    
                    return response()->json([
                        'id' => $existing->id,
                        'created' => false,
                    ], 200);
                }
            }

            // Generate dedupe_key
            $dedupeKey = $this->generateDedupeKey($source, $sourceActivityId, $startTimeIso, $durationSec, $distanceM);

            // Create summary JSON (enriched with parsed TCX blob when available)
            $summary = WorkoutSummaryBuilder::build(
                $startTimeIso,
                $durationSec,
                $distanceM,
                parsed: is_array($parsedBlob) ? $parsedBlob : [],
            );

            // Create workout
            $workout = Workout::create([
                'user_id' => $userId,
                'action' => 'save',
                'kind' => 'training',
                'summary' => $summary,
                'source' => $source,
                'source_activity_id' => $sourceActivityId,
                'dedupe_key' => $dedupeKey,
            ]);

            // Save raw TCX XML if provided
            if (!empty($validated['rawTcxXml'])) {
                WorkoutRawTcx::create([
                    'workout_id' => $workout->id,
                    'xml' => $validated['rawTcxXml'],
                    'created_at' => now(),
                ]);
            }

            // Log CREATED event
            WorkoutImportEvent::create([
                'workout_id' => $workout->id,
                'source' => $source,
                'source_activity_id' => $sourceActivityId,
                'tcx_hash' => $tcxHash,
                'status' => 'CREATED',
                'imported_at' => now(),
            ]);

            // Generate TrainingSignals v1
            $trainingSignalsService = new TrainingSignalsService();
            $trainingSignalsService->upsertForWorkout($workout->id);

            // Generate TrainingSignals v2 (only if rawTcxXml was provided)
            if (!empty($validated['rawTcxXml'])) {
                $trainingSignalsV2Service = new TrainingSignalsV2Service();
                $trainingSignalsV2Service->upsertForWorkout($workout->id);
                
                // Generate PlanCompliance v2 (only if rawTcxXml was provided)
                $planComplianceV2Service = new PlanComplianceV2Service();
                $planComplianceV2Service->upsertForWorkout($workout->id);
            }

            // Generate PlanCompliance v1
            $planComplianceService = new PlanComplianceService();
            $planComplianceService->upsertForWorkout($workout->id);

            // Generate TrainingAlerts v1
            $trainingAlertsV1Service = new TrainingAlertsV1Service();
            $trainingAlertsV1Service->upsertForWorkout($workout->id);

            return response()->json([
                'id' => $workout->id,
                'created' => true,
            ], 201);
        });
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $workout = Workout::with('rawTcx')->where('id', $id)->where('user_id', $userId)->first();

        if (!$workout) {
            return response()->json([
                'error' => 'Workout not found',
            ], 404);
        }

        $summary = $workout->summary ?? [];
        $startTimeIso = $summary['startTimeIso'] ?? null;
        $durationSec = $summary['durationSec'] ?? null;
        $distanceM = $summary['distanceM'] ?? null;

        // Ensure startTimeIso is in ISO8601 Z format
        if ($startTimeIso) {
            try {
                // Parse date - DateTime constructor handles most formats
                $dateTime = new \DateTime($startTimeIso);
                // Always convert to UTC and format as ISO8601 Z
                $dateTime->setTimezone(new \DateTimeZone('UTC'));
                $startTimeIso = $dateTime->format('Y-m-d\TH:i:s\Z');
            } catch (\Exception $e) {
                // If DateTime constructor fails, try alternative formats
                try {
                    // Try European date format (d.m.Y H:i:s)
                    $dateTime = \DateTime::createFromFormat('d.m.Y H:i:s', $startTimeIso, new \DateTimeZone('UTC'));
                    if ($dateTime) {
                        $startTimeIso = $dateTime->format('Y-m-d\TH:i:s\Z');
                    }
                } catch (\Exception $e2) {
                    // If all parsing fails, keep original value
                }
            }
        }

        $response = [
            'id' => $workout->id,
            'userId' => $workout->user_id,
            'action' => $workout->action,
            'kind' => $workout->kind,
            'summary' => is_array($workout->summary) ? $workout->summary : [],
            'raceMeta' => is_array($workout->race_meta) ? $workout->race_meta : null,
            'workoutMeta' => is_array($workout->workout_meta) ? $workout->workout_meta : null,
            'source' => $workout->source,
            'sourceActivityId' => $workout->source_activity_id,
            'startTimeIso' => $startTimeIso,
            'durationSec' => $durationSec,
            'distanceM' => $distanceM,
            'createdAt' => $workout->created_at->toIso8601String(),
            'updatedAt' => $workout->updated_at->toIso8601String(),
        ];

        if (request()->query('includeRaw') === 'true') {
            $response['tcxRaw'] = $workout->rawTcx?->xml;
        }

        return response()->json($response);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $workout = Workout::query()->where('id', $id)->where('user_id', $userId)->first();
        if (!$workout) {
            return response()->json(['error' => 'Workout not found'], 404);
        }

        $workout->delete();
        return response()->json([], 204);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $deleted = Workout::query()->where('user_id', $userId)->delete();
        return response()->json(['deleted' => $deleted]);
    }

    public function updateMeta(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'workoutMeta' => ['required', 'array'],
            'workoutMeta.planCompliance' => ['nullable', Rule::in(['planned', 'modified', 'unplanned'])],
            'workoutMeta.rpe' => ['nullable', 'numeric', 'between:1,10'],
            'workoutMeta.fatigueFlag' => ['nullable', 'boolean'],
            'workoutMeta.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $userId = $this->authUserId($request);
        $workout = Workout::where('id', $id)->where('user_id', $userId)->first();
        if (!$workout) {
            return response()->json([
                'error' => 'Workout not found',
            ], 404);
        }

        $workout->workout_meta = $validated['workoutMeta'];
        $workout->save();

        return response()->json([
            'id' => $workout->id,
            'updated' => true,
            'workoutMeta' => $workout->workout_meta,
        ]);
    }

    public function signals(int $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $workout = Workout::where('id', $id)->where('user_id', $userId)->first();
        if (!$workout) {
            return response()->json([
                'message' => 'workout not found',
            ], 404);
        }

        // Query training_signals_v1 table
        $signals = DB::table('training_signals_v1')
            ->where('workout_id', $id)
            ->first();

        if (!$signals) {
            return response()->json([
                'message' => 'signals not generated',
            ], 404);
        }

        // Format generated_at as ISO8601 Z
        $generatedAt = Carbon::parse($signals->generated_at)
            ->utc()
            ->format('Y-m-d\TH:i:s\Z');

        return response()->json([
            'workoutId' => $signals->workout_id,
            'durationSec' => $signals->duration_sec,
            'distanceM' => $signals->distance_m,
            'avgPaceSecPerKm' => $signals->avg_pace_sec_per_km,
            'durationBucket' => $signals->duration_bucket,
            'flags' => [
                'veryShort' => (bool) $signals->flag_very_short,
                'longRun' => (bool) $signals->flag_long_run,
            ],
            'generatedAtIso' => $generatedAt,
        ]);
    }

    public function compliance(int $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $workout = Workout::where('id', $id)->where('user_id', $userId)->first();
        if (!$workout) {
            return response()->json([
                'message' => 'workout not found',
            ], 404);
        }

        // Query plan_compliance_v1 table
        $compliance = DB::table('plan_compliance_v1')
            ->where('workout_id', $id)
            ->first();

        if (!$compliance) {
            return response()->json([
                'message' => 'compliance not generated',
            ], 404);
        }

        // Format generated_at as ISO8601 Z
        $generatedAt = Carbon::parse($compliance->generated_at)
            ->utc()
            ->format('Y-m-d\TH:i:s\Z');

        return response()->json([
            'workoutId' => $compliance->workout_id,
            'expectedDurationSec' => $compliance->expected_duration_sec,
            'actualDurationSec' => $compliance->actual_duration_sec,
            'deltaDurationSec' => $compliance->delta_duration_sec,
            'durationRatio' => $compliance->duration_ratio,
            'status' => $compliance->status,
            'flags' => [
                'overshootDuration' => (bool) $compliance->flag_overshoot_duration,
                'undershootDuration' => (bool) $compliance->flag_undershoot_duration,
            ],
            'generatedAtIso' => $generatedAt,
        ]);
    }

    public function complianceV2(int $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $workout = Workout::where('id', $id)->where('user_id', $userId)->first();
        if (!$workout) {
            return response()->json([
                'message' => 'workout not found',
            ], 404);
        }

        // Query plan_compliance_v2 table
        $compliance = DB::table('plan_compliance_v2')
            ->where('workout_id', $id)
            ->first();

        if (!$compliance) {
            return response()->json([
                'message' => 'compliance not generated',
            ], 404);
        }

        // Format generated_at as ISO8601 Z
        $generatedAt = Carbon::parse($compliance->generated_at)
            ->utc()
            ->format('Y-m-d\TH:i:s\Z');

        return response()->json([
            'workoutId' => $compliance->workout_id,
            'expectedHrZoneMin' => $compliance->expected_hr_zone_min,
            'expectedHrZoneMax' => $compliance->expected_hr_zone_max,
            'actualHrZ1Sec' => $compliance->actual_hr_z1_sec,
            'actualHrZ2Sec' => $compliance->actual_hr_z2_sec,
            'actualHrZ3Sec' => $compliance->actual_hr_z3_sec,
            'actualHrZ4Sec' => $compliance->actual_hr_z4_sec,
            'actualHrZ5Sec' => $compliance->actual_hr_z5_sec,
            'highIntensitySec' => $compliance->high_intensity_sec,
            'highIntensityRatio' => $compliance->high_intensity_ratio,
            'status' => $compliance->status,
            'easyBecameZ5' => (bool) ($compliance->flag_easy_became_z5 ?? false),
            'generatedAtIso' => $generatedAt,
        ]);
    }

    public function alertsV1(int $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $workout = Workout::where('id', $id)->where('user_id', $userId)->first();
        if (!$workout) {
            return response()->json([
                'message' => 'workout not found',
            ], 404);
        }

        // Query training_alerts_v1 table
        $alerts = DB::table('training_alerts_v1')
            ->where('workout_id', $id)
            ->orderBy('code', 'asc')
            ->get();

        $result = [];
        foreach ($alerts as $alert) {
            $generatedAt = Carbon::parse($alert->generated_at)
                ->utc()
                ->format('Y-m-d\TH:i:s\Z');

            $result[] = [
                'code' => $alert->code,
                'severity' => $alert->severity,
                'payloadJson' => $alert->payload_json ? json_decode($alert->payload_json, true) : null,
                'generatedAtIso' => $generatedAt,
            ];
        }

        return response()->json($result);
    }

    /**
     * Parse raw TCX into the rich canonical blob used to build the summary.
     * Throws \InvalidArgumentException on fatal errors (invalid XML, missing
     * activity id, missing laps, missing TotalTimeSeconds).
     */
    private function parseTcxXml(string $xml, ?int $userId = null): array
    {
        $hrZones = $userId !== null ? $this->loadHrZones($userId) : null;
        return app(TcxParsingService::class)->parse($xml, $hrZones);
    }

    /**
     * Best-effort parse for the manual POST path — returns null on malformed
     * input so we never break the legacy create() flow when the client-provided
     * summary was correct but the XML was slightly off.
     */
    private function tryParseRichBlob(string $xml, ?int $userId): ?array
    {
        if (trim($xml) === '') {
            return null;
        }
        try {
            return $this->parseTcxXml($xml, $userId);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Loads the user's configured HR zones from the user_profiles table, if the
     * row exists and all z1..z5 thresholds are set. Returns null otherwise so
     * TcxParsingService falls back to pace-based/lumped buckets.
     *
     * @return array<string,array{min:int,max:int}>|null
     */
    private function loadHrZones(int $userId): ?array
    {
        $profile = DB::table('user_profiles')->where('user_id', $userId)->first([
            'hr_z1_min', 'hr_z1_max',
            'hr_z2_min', 'hr_z2_max',
            'hr_z3_min', 'hr_z3_max',
            'hr_z4_min', 'hr_z4_max',
            'hr_z5_min', 'hr_z5_max',
        ]);
        if (!$profile) {
            return null;
        }
        $zones = [];
        for ($i = 1; $i <= 5; $i++) {
            $min = $profile->{"hr_z{$i}_min"} ?? null;
            $max = $profile->{"hr_z{$i}_max"} ?? null;
            if (!is_numeric($min) || !is_numeric($max)) {
                return null;
            }
            $zones["z{$i}"] = ['min' => (int) $min, 'max' => (int) $max];
        }
        return $zones;
    }

    private function generateDedupeKey(string $source, ?string $sourceActivityId, string $startTimeIso, int $durationSec, int $distanceM): string
    {
        return WorkoutSourceContract::buildDedupeKey($source, $sourceActivityId, $startTimeIso, $durationSec, $distanceM);
    }

    private function buildAnalyticsRow(Workout $workout): ?array
    {
        $summary = is_array($workout->summary) ? $workout->summary : [];
        $distanceM = $summary['trimmed']['distanceM'] ?? $summary['original']['distanceM'] ?? null;
        $durationSec = $summary['trimmed']['durationSec'] ?? $summary['original']['durationSec'] ?? null;

        if (!is_numeric($distanceM) || !is_numeric($durationSec)) {
            return null;
        }

        $intensityBuckets = is_array($summary['intensityBuckets'] ?? null) ? $summary['intensityBuckets'] : [];
        $intensity = is_array($summary['intensity'] ?? null) ? $summary['intensity'] : $intensityBuckets;
        $toMin = fn ($sec) => is_numeric($sec) ? round(((float) $sec) / 60, 2) : 0.0;
        $workoutDt = (string) ($summary['startTimeIso'] ?? $workout->created_at?->toIso8601String());
        $explicitSport = strtolower((string) ($summary['sport'] ?? ''));
        if ($explicitSport !== '') {
            $type = in_array($explicitSport, ['run', 'bike', 'swim', 'other'], true) ? $explicitSport : 'other';
        } else {
            // Legacy fallback: best-effort string match (used for pre-M2-beyond summaries).
            $sportGuess = strtolower((string) (($summary['kind'] ?? '') . ' ' . ($summary['sport'] ?? '')));
            $type = str_contains($sportGuess, 'run') || str_contains($sportGuess, 'bieg') ? 'run' : 'other';
        }
        $avgPaceSecPerKm = isset($summary['avgPaceSecPerKm']) && is_numeric($summary['avgPaceSecPerKm'])
            ? (int) $summary['avgPaceSecPerKm']
            : null;

        return [
            'workoutId' => $workout->id,
            'workoutDt' => $workoutDt,
            'distanceKm' => round(((float) $distanceM) / 1000, 2),
            'durationMin' => round(((float) $durationSec) / 60, 2),
            'type' => $type,
            'avgPaceSecPerKm' => $avgPaceSecPerKm,
            'intensity' => [
                'z1Min' => $toMin($intensity['z1Sec'] ?? 0),
                'z2Min' => $toMin($intensity['z2Sec'] ?? 0),
                'z3Min' => $toMin($intensity['z3Sec'] ?? 0),
                'z4Min' => $toMin($intensity['z4Sec'] ?? 0),
                'z5Min' => $toMin($intensity['z5Sec'] ?? 0),
            ],
        ];
    }
}

