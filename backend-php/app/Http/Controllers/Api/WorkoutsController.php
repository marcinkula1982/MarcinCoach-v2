<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workout;
use App\Models\WorkoutRawTcx;
use App\Models\WorkoutImportEvent;
use App\Services\PlanComplianceService;
use App\Services\PlanComplianceV2Service;
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
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', Rule::in(['garmin', 'tcx', 'manual'])],
            'sourceActivityId' => ['nullable', 'string'],
            'startTimeIso' => ['required', 'date'],
            'durationSec' => ['required', 'integer', 'min:1'],
            'distanceM' => ['required', 'integer', 'min:0'],
            'rawTcxXml' => ['nullable', 'string'],
        ]);

        $source = $validated['source'];
        $sourceActivityId = $validated['sourceActivityId'] ?? null;
        
        // Parse TCX XML if source is 'tcx' and rawTcxXml is provided
        $startTimeIso = $validated['startTimeIso'];
        $durationSec = $validated['durationSec'];
        $distanceM = $validated['distanceM'];
        
        if ($source === 'tcx' && !empty($validated['rawTcxXml'])) {
            try {
                $parsed = $this->parseTcxXml($validated['rawTcxXml']);
                $startTimeIso = $parsed['startTimeIso'];
                $durationSec = $parsed['durationSec'];
                $distanceM = $parsed['distanceM'];
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }
        }
        
        // Get or create default user for now (no auth)
        $user = \App\Models\User::firstOrCreate(
            ['id' => 1],
            ['name' => 'Default User', 'email' => 'default@example.com', 'password' => bcrypt('password')]
        );
        $userId = $user->id;

        // Calculate tcx_hash if rawTcxXml is provided
        $tcxHash = null;
        if (!empty($validated['rawTcxXml'])) {
            $tcxHash = hash('sha256', $validated['rawTcxXml']);
        }

        // Wrap everything in a transaction
        return DB::transaction(function () use ($source, $sourceActivityId, $userId, $startTimeIso, $durationSec, $distanceM, $validated, $tcxHash) {
            // Check for existing workout if sourceActivityId is provided
            $existing = null;
            if ($sourceActivityId !== null) {
                $existing = Workout::where('source', $source)
                    ->where('source_activity_id', $sourceActivityId)
                    ->where('user_id', $userId)
                    ->first();

                if ($existing) {
                    // UPSERT: Update existing workout if source is 'tcx' and rawTcxXml is provided
                    if ($source === 'tcx' && !empty($validated['rawTcxXml'])) {
                        // Generate dedupe_key with new values
                        $dedupeKey = $this->generateDedupeKey($source, $sourceActivityId, $startTimeIso, $durationSec, $distanceM);
                        
                        // Update summary
                        $summary = [
                            'startTimeIso' => $startTimeIso,
                            'durationSec' => $durationSec,
                            'distanceM' => $distanceM,
                        ];
                        
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

            // Create summary JSON
            $summary = [
                'startTimeIso' => $startTimeIso,
                'durationSec' => $durationSec,
                'distanceM' => $distanceM,
            ];

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

    public function show(int $id): JsonResponse
    {
        $workout = Workout::find($id);

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

        return response()->json([
            'id' => $workout->id,
            'source' => $workout->source,
            'sourceActivityId' => $workout->source_activity_id,
            'startTimeIso' => $startTimeIso,
            'durationSec' => $durationSec,
            'distanceM' => $distanceM,
            'createdAt' => $workout->created_at->toIso8601String(),
            'updatedAt' => $workout->updated_at->toIso8601String(),
        ]);
    }

    public function signals(int $id): JsonResponse
    {
        // Check if workout exists
        $workout = Workout::find($id);
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

    public function compliance(int $id): JsonResponse
    {
        // Check if workout exists
        $workout = Workout::find($id);
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

    public function complianceV2(int $id): JsonResponse
    {
        // Check if workout exists
        $workout = Workout::find($id);
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

    public function alertsV1(int $id): JsonResponse
    {
        // Check if workout exists
        $workout = Workout::find($id);
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

    private function parseTcxXml(string $xml): array
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        
        // Suppress errors for invalid XML, we'll handle it ourselves
        $oldErrorReporting = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($oldErrorReporting);

        if (!$loaded || !empty($errors)) {
            throw new \InvalidArgumentException('Invalid TCX XML format');
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('tcx', 'http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2');

        // Extract startTimeIso from first Activity/Id
        $idNodes = $xpath->query('//tcx:Activity/tcx:Id');
        if ($idNodes->length === 0) {
            throw new \InvalidArgumentException('Missing Activity/Id in TCX');
        }
        $startTimeIsoRaw = trim($idNodes->item(0)->textContent);
        
        // Ensure ISO8601 Z format
        try {
            $dateTime = new \DateTime($startTimeIsoRaw, new \DateTimeZone('UTC'));
            $startTimeIso = $dateTime->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid date format in Activity/Id: ' . $startTimeIsoRaw);
        }

        // Extract durationSec and distanceM from all Lap elements
        $lapNodes = $xpath->query('//tcx:Lap');
        if ($lapNodes->length === 0) {
            throw new \InvalidArgumentException('No Lap elements found in TCX');
        }

        $totalDurationSec = 0;
        $totalDistanceM = 0;

        foreach ($lapNodes as $lapNode) {
            // Get TotalTimeSeconds
            $timeNodes = $xpath->query('./tcx:TotalTimeSeconds', $lapNode);
            if ($timeNodes->length === 0) {
                throw new \InvalidArgumentException('Missing TotalTimeSeconds in TCX Lap');
            }
            $totalDurationSec += (float) trim($timeNodes->item(0)->textContent);

            // Get DistanceMeters
            $distanceNodes = $xpath->query('./tcx:DistanceMeters', $lapNode);
            if ($distanceNodes->length === 0) {
                throw new \InvalidArgumentException('Missing DistanceMeters in TCX Lap');
            }
            $totalDistanceM += (float) trim($distanceNodes->item(0)->textContent);
        }

        return [
            'startTimeIso' => $startTimeIso,
            'durationSec' => (int) round($totalDurationSec),
            'distanceM' => (int) round($totalDistanceM),
        ];
    }

    private function generateDedupeKey(string $source, ?string $sourceActivityId, string $startTimeIso, int $durationSec, int $distanceM): string
    {
        if ($sourceActivityId !== null) {
            return "{$source}:{$sourceActivityId}";
        }

        // Generate key from normalized data
        $normalizedDate = date('Y-m-d', strtotime($startTimeIso));
        $normalizedDuration = round($durationSec / 60) * 60; // Round to nearest minute
        $normalizedDistance = round($distanceM / 100) * 100; // Round to nearest 100m

        return "{$source}:{$normalizedDate}:{$normalizedDuration}:{$normalizedDistance}";
    }
}

