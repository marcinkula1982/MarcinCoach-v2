<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationAccount;
use App\Models\IntegrationSyncRun;
use App\Services\ExternalWorkoutImportService;
use App\Services\GarminConnectorService;
use App\Services\StravaOAuthService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class IntegrationsController extends Controller
{
    public function __construct(
        private readonly StravaOAuthService $stravaOAuthService,
        private readonly GarminConnectorService $garminConnectorService,
        private readonly ExternalWorkoutImportService $externalWorkoutImportService,
    ) {
    }

    public function stravaConnect(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $result = $this->stravaOAuthService->buildConnectUrl($userId);
        return response()->json($result);
    }

    public function stravaCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:1'],
            'state' => ['required', 'string', 'min:1'],
        ]);
        $userId = $this->authUserId($request);
        $result = $this->stravaOAuthService->handleCallback($userId, (string) $validated['code'], (string) $validated['state']);
        if (!($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }
        return response()->json($result);
    }

    public function stravaSync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fromIso' => ['nullable', 'date'],
            'toIso' => ['nullable', 'date'],
        ]);
        $userId = $this->authUserId($request);
        $token = $this->stravaOAuthService->getValidAccessToken($userId);
        if (!$token) {
            return response()->json(['message' => 'Strava not connected'], 422);
        }

        $syncRun = IntegrationSyncRun::create([
            'user_id' => $userId,
            'provider' => 'strava',
            'status' => 'started',
            'started_at' => now(),
        ]);

        $res = Http::withToken($token)->timeout(45)->get('https://www.strava.com/api/v3/athlete/activities', [
            'after' => isset($validated['fromIso']) ? strtotime((string) $validated['fromIso']) : null,
            'before' => isset($validated['toIso']) ? strtotime((string) $validated['toIso']) : null,
            'per_page' => 30,
            'page' => 1,
        ]);
        if (!$res->successful()) {
            $syncRun->status = 'failed';
            $syncRun->error_code = 'STRAVA_SYNC_FAILED';
            $syncRun->finished_at = now();
            $syncRun->save();
            return response()->json(['message' => 'Strava sync failed'], 502);
        }
        $rows = is_array($res->json()) ? $res->json() : [];
        $activities = array_values(array_map(function ($a) {
            $activityType = (string) ($a['sport_type'] ?? $a['type'] ?? '');

            return [
                'sourceActivityId' => (string) ($a['id'] ?? ''),
                'startTimeIso' => (string) ($a['start_date'] ?? ''),
                'durationSec' => (int) ($a['elapsed_time'] ?? 0),
                'distanceM' => (int) round((float) ($a['distance'] ?? 0)),
                'activityType' => $activityType,
                'sport' => $activityType,
                'averageHr' => isset($a['average_heartrate']) ? (float) $a['average_heartrate'] : null,
                'maxHr' => isset($a['max_heartrate']) ? (float) $a['max_heartrate'] : null,
            ];
        }, $rows));

        $stats = $this->externalWorkoutImportService->importActivities($userId, 'strava', $activities);
        $syncRun->status = $stats['failed'] > 0 ? 'partial' : 'success';
        $syncRun->fetched_count = $stats['fetched'];
        $syncRun->imported_count = $stats['imported'];
        $syncRun->deduped_count = $stats['deduped'];
        $syncRun->failed_count = $stats['failed'];
        $syncRun->finished_at = now();
        $syncRun->save();
        IntegrationAccount::query()->updateOrCreate(
            ['user_id' => $userId, 'provider' => 'strava'],
            ['last_sync_at' => now(), 'status' => 'connected'],
        );

        return response()->json([
            'syncRunId' => $syncRun->id,
            ...$stats,
        ]);
    }

    public function garminConnect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'garminEmail'    => ['nullable', 'required_with:garminPassword', 'email', 'max:255'],
            'garminPassword' => ['nullable', 'required_with:garminEmail', 'string', 'min:1', 'max:255'],
        ]);
        $userId = $this->authUserId($request);
        $result = $this->garminConnectorService->startConnect(
            $userId,
            isset($validated['garminEmail']) ? (string) $validated['garminEmail'] : null,
            isset($validated['garminPassword']) ? (string) $validated['garminPassword'] : null,
        );
        if (!$result['ok']) {
            return response()->json($result['payload'], 502);
        }
        IntegrationAccount::query()->updateOrCreate(
            ['user_id' => $userId, 'provider' => 'garmin'],
            ['status' => 'connected', 'meta' => $result['payload']],
        );
        return response()->json($result['payload']);
    }

    public function garminSync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fromIso'      => ['nullable', 'date'],
            'toIso'        => ['nullable', 'date'],
            'activityType' => ['nullable', 'string', 'max:64'],
        ]);
        $userId = $this->authUserId($request);
        $syncRun = IntegrationSyncRun::create([
            'user_id' => $userId,
            'provider' => 'garmin',
            'status' => 'started',
            'started_at' => now(),
        ]);
        $result = $this->garminConnectorService->sync(
            $userId,
            $validated['fromIso'] ?? null,
            $validated['toIso'] ?? null,
            $validated['activityType'] ?? null,
        );
        if (!$result['ok']) {
            $syncRun->status = 'failed';
            $syncRun->error_code = (string) ($result['payload']['error'] ?? 'GARMIN_SYNC_FAILED');
            $syncRun->finished_at = now();
            $syncRun->save();
            return response()->json($result['payload'], 502);
        }
        $activities = is_array($result['payload']['items'] ?? null) ? $result['payload']['items'] : [];
        $stats = $this->externalWorkoutImportService->importActivities($userId, 'garmin', $activities);
        $syncRun->status = $stats['failed'] > 0 ? 'partial' : 'success';
        $syncRun->fetched_count = $stats['fetched'];
        $syncRun->imported_count = $stats['imported'];
        $syncRun->deduped_count = $stats['deduped'];
        $syncRun->failed_count = $stats['failed'];
        $syncRun->finished_at = now();
        $syncRun->meta = $result['payload'];
        $syncRun->save();
        IntegrationAccount::query()->updateOrCreate(
            ['user_id' => $userId, 'provider' => 'garmin'],
            ['last_sync_at' => now(), 'status' => 'connected'],
        );

        return response()->json([
            'syncRunId' => $syncRun->id,
            ...$stats,
        ]);
    }

    public function garminSendWorkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['nullable', 'string', 'max:80'],
            'session' => ['required', 'array'],
            'session.day' => ['nullable', 'string', 'max:16'],
            'session.type' => ['required', 'string', 'max:64'],
            'session.durationMin' => ['required', 'integer', 'min:1', 'max:360'],
            'session.intensityHint' => ['nullable', 'string', 'max:32'],
            'session.structure' => ['nullable', 'string', 'max:255'],
            'session.notes' => ['nullable', 'array'],
            'session.notes.*' => ['string', 'max:160'],
        ]);

        $userId = $this->authUserId($request);
        $session = $validated['session'];
        $scheduledDate = CarbonImmutable::parse((string) $validated['date'])->toDateString();

        $payload = [
            'date' => $scheduledDate,
            'workoutName' => (string) ($validated['name'] ?? $this->defaultGarminWorkoutName($session, $scheduledDate)),
            'day' => isset($session['day']) ? (string) $session['day'] : null,
            'type' => (string) $session['type'],
            'durationMin' => (int) $session['durationMin'],
            'intensityHint' => isset($session['intensityHint']) ? (string) $session['intensityHint'] : null,
            'structure' => isset($session['structure']) ? (string) $session['structure'] : null,
            'notes' => array_values(array_map('strval', $session['notes'] ?? [])),
        ];

        $result = $this->garminConnectorService->sendWorkout($userId, $payload);
        if (!$result['ok']) {
            return response()->json($result['payload'], 502);
        }

        return response()->json($result['payload']);
    }

    public function garminStatus(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $result = $this->garminConnectorService->status($userId);
        if (!$result['ok']) {
            return response()->json($result['payload'], 502);
        }
        return response()->json($result['payload']);
    }

    /**
     * @param array<string,mixed> $session
     */
    private function defaultGarminWorkoutName(array $session, string $scheduledDate): string
    {
        $type = (string) ($session['type'] ?? 'run');
        return substr("MarcinCoach {$type} {$scheduledDate}", 0, 80);
    }
}
