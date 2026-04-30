<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SuuntoSportsTrackerService
{
    private const DEFAULT_BASE_URL = 'https://api.sports-tracker.com/apiserver/v1';

    public function __construct(
        private readonly FitParsingService $fitParsingService,
        private readonly GpxParsingService $gpxParsingService,
    ) {
    }

    public function enabled(): bool
    {
        return filter_var(env('SUUNTO_SPORTS_TRACKER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{ok:bool,payload:array<string,mixed>}
     */
    public function fetchActivities(
        string $sessionToken,
        int $limit,
        string $format,
        ?string $fromIso = null,
        ?string $toIso = null,
    ): array {
        $list = $this->workoutList($sessionToken, $limit);
        if (!$list['ok']) {
            return $list;
        }

        $rows = $list['payload']['rows'];
        $activities = [];
        $errors = [];

        foreach ($rows as $row) {
            $key = $this->workoutKey($row);
            if ($key === null) {
                $errors[] = ['stage' => 'metadata', 'error' => 'MISSING_WORKOUT_KEY'];

                continue;
            }

            $download = $this->downloadWorkout($sessionToken, $key, $format);
            if (!$download['ok']) {
                $errors[] = ['stage' => 'download', 'workoutKey' => $key] + $download['payload'];

                continue;
            }

            try {
                $parsed = $format === 'fit'
                    ? $this->fitParsingService->parse((string) $download['payload']['content'])
                    : $this->gpxParsingService->parse((string) $download['payload']['content']);
            } catch (\InvalidArgumentException $e) {
                $errors[] = [
                    'stage' => 'parse',
                    'workoutKey' => $key,
                    'error' => 'SUUNTO_EXPORT_PARSE_FAILED',
                    'message' => $e->getMessage(),
                ];

                continue;
            }

            if (!$this->insideWindow((string) $parsed['startTimeIso'], $fromIso, $toIso)) {
                continue;
            }

            $sport = isset($parsed['sport']) && is_string($parsed['sport'])
                ? $parsed['sport']
                : $this->sportFromMetadata($row);

            $activities[] = [
                'sourceActivityId' => $key,
                'startTimeIso' => (string) $parsed['startTimeIso'],
                'durationSec' => (int) $parsed['durationSec'],
                'distanceM' => (int) $parsed['distanceM'],
                'activityType' => $sport,
                'sport' => $sport,
                'averageHr' => $parsed['hr']['avgBpm'] ?? null,
                'maxHr' => $parsed['hr']['maxBpm'] ?? null,
                'parsed' => $parsed,
            ];
        }

        return [
            'ok' => true,
            'payload' => [
                'items' => $activities,
                'fetchedKeys' => count($rows),
                'downloaded' => count($activities),
                'failed' => count($errors),
                'errors' => $errors,
                'format' => $format,
                'connector' => 'sports_tracker_unofficial',
            ],
        ];
    }

    /**
     * @return array{ok:bool,payload:array<string,mixed>}
     */
    private function workoutList(string $sessionToken, int $limit): array
    {
        try {
            $response = Http::timeout(20)->get($this->baseUrl() . '/workouts', [
                'limited' => 'true',
                'limit' => $limit,
                'token' => $sessionToken,
            ]);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'payload' => ['error' => 'SUUNTO_SPORTS_TRACKER_UNREACHABLE', 'message' => $e->getMessage()]];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'payload' => $this->failurePayload($response, 'SUUNTO_SPORTS_TRACKER_LIST_FAILED')];
        }

        $json = $response->json();
        if (!is_array($json)) {
            return ['ok' => false, 'payload' => ['error' => 'SUUNTO_SPORTS_TRACKER_BAD_LIST']];
        }

        $rows = is_array($json['payload'] ?? null)
            ? $json['payload']
            : (array_is_list($json) ? $json : []);

        return ['ok' => true, 'payload' => ['rows' => array_values(array_filter($rows, 'is_array'))]];
    }

    /**
     * @return array{ok:bool,payload:array<string,mixed>}
     */
    private function downloadWorkout(string $sessionToken, string $workoutKey, string $format): array
    {
        $exportPath = $format === 'fit' ? 'exportFit' : 'exportGpx';

        try {
            $response = Http::timeout(30)->get($this->baseUrl() . "/workout/{$exportPath}/{$workoutKey}", [
                'token' => $sessionToken,
            ]);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'payload' => ['error' => 'SUUNTO_SPORTS_TRACKER_EXPORT_UNREACHABLE', 'message' => $e->getMessage()]];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'payload' => $this->failurePayload($response, 'SUUNTO_SPORTS_TRACKER_EXPORT_FAILED')];
        }

        $body = $response->body();
        if ($body === '') {
            return ['ok' => false, 'payload' => ['error' => 'SUUNTO_SPORTS_TRACKER_EMPTY_EXPORT']];
        }

        return ['ok' => true, 'payload' => ['content' => $body]];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function workoutKey(array $row): ?string
    {
        foreach (['workoutKey', 'workout_key', 'workoutId', 'id', 'key'] as $field) {
            if (isset($row[$field]) && (is_string($row[$field]) || is_numeric($row[$field]))) {
                $value = trim((string) $row[$field]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function sportFromMetadata(array $row): string
    {
        $activityId = isset($row['activityId']) && is_numeric($row['activityId']) ? (int) $row['activityId'] : null;

        return match ($activityId) {
            0 => 'walk',
            1 => 'run',
            2 => 'bike',
            11 => 'walk_hike',
            13 => 'alpine_ski',
            14, 15 => 'rowing',
            default => 'other',
        };
    }

    private function insideWindow(string $startTimeIso, ?string $fromIso, ?string $toIso): bool
    {
        try {
            $start = CarbonImmutable::parse($startTimeIso)->utc();
            $from = $fromIso !== null ? CarbonImmutable::parse($fromIso)->utc() : null;
            $to = $toIso !== null ? CarbonImmutable::parse($toIso)->utc() : null;
        } catch (\Throwable) {
            return true;
        }

        if ($from !== null && $start->lt($from)) {
            return false;
        }
        if ($to !== null && $start->gt($to)) {
            return false;
        }

        return true;
    }

    private function baseUrl(): string
    {
        return rtrim((string) env('SUUNTO_SPORTS_TRACKER_BASE_URL', self::DEFAULT_BASE_URL), '/');
    }

    /**
     * @return array<string,mixed>
     */
    private function failurePayload(Response $response, string $defaultError): array
    {
        $json = $response->json();
        if (is_array($json) && isset($json['detail']) && is_array($json['detail'])) {
            return $json['detail'];
        }
        if (is_array($json)) {
            return $json + ['error' => $json['error'] ?? $defaultError];
        }

        return [
            'error' => $defaultError,
            'message' => $response->body(),
        ];
    }
}
