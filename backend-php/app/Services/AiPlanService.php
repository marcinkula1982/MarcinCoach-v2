<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AiPlanService
{
    public function __construct(
        private readonly AiCacheService $aiCacheService,
        private readonly PlanSnapshotService $planSnapshotService,
        private readonly AiObservabilityService $observabilityService,
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $adjustments
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public function buildResponse(int $userId, array $context, array $adjustments, array $plan): array
    {
        $days = (int) ($context['windowDays'] ?? 28);
        $cached = $this->aiCacheService->get('plan', $userId, $days);
        if (is_array($cached)) {
            $cached['provider'] = 'cache';
            return $cached;
        }

        $provider = strtolower((string) env('AI_PLAN_PROVIDER', 'stub'));
        $explanation = $provider === 'openai'
            ? $this->buildOpenAiExplanation($context, $adjustments, $plan)
            : $this->buildStubExplanation($context, $adjustments, $plan);

        $response = [
            'provider' => $provider === 'openai' ? 'openai' : 'stub',
            'generatedAtIso' => $context['generatedAtIso'],
            'windowDays' => $days,
            'plan' => $plan,
            'adjustments' => $adjustments,
            'explanation' => $explanation,
        ];

        $snapshot = $this->toPlanSnapshot($plan);
        $this->planSnapshotService->saveForUser($userId, $snapshot);
        $this->aiCacheService->set('plan', $userId, $days, $response);

        return $response;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $adjustments
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function buildStubExplanation(array $context, array $adjustments, array $plan): array
    {
        $summary = [
            sprintf('Okno danych: %d dni.', (int) ($context['windowDays'] ?? 28)),
            sprintf('Dni treningowe w tygodniu: %d.', count(array_filter($plan['sessions'] ?? [], fn ($s) => ($s['type'] ?? '') !== 'rest'))),
        ];
        $warnings = [];
        foreach (($adjustments['adjustments'] ?? []) as $adj) {
            if (($adj['code'] ?? '') === 'reduce_load') {
                $warnings[] = 'Zredukowano obciążenie z powodu oznak zmęczenia.';
            }
        }

        return [
            'titlePl' => 'Plan tygodniowy',
            'summaryPl' => array_values(array_slice($summary, 0, 6)),
            'sessionNotesPl' => [],
            'warningsPl' => array_values($warnings),
            'confidence' => 0.65,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $adjustments
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function buildOpenAiExplanation(array $context, array $adjustments, array $plan): array
    {
        $apiKey = trim((string) env('OPENAI_API_KEY', ''));
        if ($apiKey === '') {
            $this->observabilityService->warn('plan.openai_missing_api_key');
            return $this->buildStubExplanation($context, $adjustments, $plan);
        }

        $model = (string) env('AI_PLAN_MODEL', 'gpt-5');
        $maxTokens = (int) env('AI_PLAN_MAX_OUTPUT_TOKENS', 2000);
        $instructions =
            'Napisz po polsku krótkie objaśnienie planu tygodniowego. Zwróć wyłącznie JSON: ' .
            '{"titlePl":string,"summaryPl":string[],"sessionNotesPl":{"day":string,"text":string}[],"warningsPl":string[],"confidence":number}.';

        $response = Http::withToken($apiKey)
            ->timeout(45)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'instructions' => $instructions,
                'input' => json_encode(['context' => $context, 'adjustments' => $adjustments, 'plan' => $plan], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'max_output_tokens' => $maxTokens,
            ]);

        if (!$response->successful()) {
            $this->observabilityService->warn('plan.openai_http_error', [
                'status' => $response->status(),
            ]);
            return $this->buildStubExplanation($context, $adjustments, $plan);
        }
        $output = data_get($response->json(), 'output_text');
        if (!is_string($output) || trim($output) === '') {
            $this->observabilityService->warn('plan.openai_empty_output');
            return $this->buildStubExplanation($context, $adjustments, $plan);
        }

        $decoded = json_decode($this->stripMarkdownFences($output), true);
        if (!is_array($decoded)) {
            $this->observabilityService->warn('plan.openai_invalid_json');
            return $this->buildStubExplanation($context, $adjustments, $plan);
        }

        return [
            'titlePl' => (string) ($decoded['titlePl'] ?? 'Plan tygodniowy'),
            'summaryPl' => array_values(array_slice(is_array($decoded['summaryPl'] ?? null) ? $decoded['summaryPl'] : [], 0, 6)),
            'sessionNotesPl' => is_array($decoded['sessionNotesPl'] ?? null) ? $decoded['sessionNotesPl'] : [],
            'warningsPl' => array_values(is_array($decoded['warningsPl'] ?? null) ? $decoded['warningsPl'] : []),
            'confidence' => max(0.0, min(1.0, is_numeric($decoded['confidence'] ?? null) ? (float) $decoded['confidence'] : 0.6)),
        ];
    }

    private function stripMarkdownFences(string $raw): string
    {
        $trimmed = trim($raw);
        if (!Str::startsWith($trimmed, '```')) {
            return $trimmed;
        }
        $trimmed = preg_replace('/^```[a-zA-Z]*\n?/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/```$/', '', $trimmed) ?? $trimmed;
        return trim($trimmed);
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function toPlanSnapshot(array $plan): array
    {
        $sessions = is_array($plan['sessions'] ?? null) ? $plan['sessions'] : [];
        $days = [];
        $dayOffsets = [
            'mon' => 0,
            'tue' => 1,
            'wed' => 2,
            'thu' => 3,
            'fri' => 4,
            'sat' => 5,
            'sun' => 6,
        ];
        $weekStart = new \DateTimeImmutable((string) ($plan['weekStartIso'] ?? now()->toISOString()));
        foreach ($sessions as $s) {
            $day = (string) ($s['day'] ?? 'mon');
            $offset = $dayOffsets[$day] ?? 0;
            $date = $weekStart->modify("+{$offset} days");
            $days[] = [
                'dateKey' => $date->format('Y-m-d'),
                'type' => (string) ($s['type'] ?? 'rest'),
                'plannedDurationMin' => (int) ($s['durationMin'] ?? 0),
                'plannedDistanceKm' => isset($s['distanceKm']) ? (float) $s['distanceKm'] : null,
                'plannedIntensity' => (string) (($s['intensityHint'] ?? 'Z2')),
            ];
        }

        return [
            'windowStartIso' => (string) ($plan['weekStartIso'] ?? ''),
            'windowEndIso' => (string) ($plan['weekEndIso'] ?? ''),
            'days' => $days,
        ];
    }
}
