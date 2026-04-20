<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AiInsightsService
{
    public function __construct(
        private readonly TrainingFeedbackService $trainingFeedbackService,
        private readonly UserProfileService $userProfileService,
        private readonly AiCacheService $aiCacheService,
        private readonly AiObservabilityService $observabilityService,
    ) {
    }

    /**
     * @return array{payload:array<string,mixed>,cache:'hit'|'miss'}
     */
    public function getInsightsForUser(int $userId, string $username, int $days = 28): array
    {
        $cached = $this->aiCacheService->get('insights', $userId, $days);
        if (is_array($cached)) {
            return ['payload' => $cached, 'cache' => 'hit'];
        }

        $feedback = $this->trainingFeedbackService->getFeedbackForUser($userId, $days);
        $constraints = $this->userProfileService->getConstraintsForUser($userId);
        $payload = [
            'user' => [
                'username' => $username,
                'hrZones' => $constraints['hrZones'] ?? null,
            ],
            'feedback' => $feedback,
        ];

        $provider = strtolower((string) env('AI_INSIGHTS_PROVIDER', 'stub'));
        $insights = $provider === 'openai'
            ? $this->callOpenAi($payload)
            : $this->buildStubInsights($payload);

        $normalized = [
            'generatedAtIso' => $feedback['generatedAtIso'],
            'windowDays' => $feedback['windowDays'],
            'summary' => array_values(array_slice($insights['summary'] ?? [], 0, 5)),
            'risks' => $this->normalizeRisks($insights['risks'] ?? ['none']),
            'questions' => array_values(array_slice($insights['questions'] ?? [], 0, 3)),
            'confidence' => $this->normalizeConfidence($insights['confidence'] ?? 0.5),
        ];

        $this->aiCacheService->set('insights', $userId, $days, $normalized);

        return ['payload' => $normalized, 'cache' => 'miss'];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function buildStubInsights(array $payload): array
    {
        $feedback = $payload['feedback'] ?? [];
        $totalSessions = (int) ($feedback['counts']['totalSessions'] ?? 0);
        $windowDays = (int) ($feedback['windowDays'] ?? 28);
        if ($totalSessions === 0) {
            return [
                'generatedAtIso' => $feedback['generatedAtIso'] ?? now()->toISOString(),
                'windowDays' => $windowDays,
                'summary' => ["Brak danych w ostatnich {$windowDays} dniach (brak treningów w oknie)."],
                'risks' => ['none'],
                'questions' => ['Czy zapisy treningów są kompletne w aplikacji?'],
                'confidence' => 0.2,
            ];
        }

        $risks = [];
        if ((float) ($feedback['complianceRate']['unplannedPct'] ?? 0) >= 50.0) {
            $risks[] = 'low-compliance';
        }
        if ((int) ($feedback['fatigue']['trueCount'] ?? 0) >= 2) {
            $risks[] = 'fatigue';
        }
        if ($totalSessions < max(1, intdiv($windowDays, 7))) {
            $risks[] = 'inconsistency';
        }
        if ($risks === []) {
            $risks = ['none'];
        }

        $summary = ["W oknie {$windowDays} dni: {$totalSessions} sesji."];
        if (in_array('low-compliance', $risks, true)) {
            $summary[] = 'Wysoki odsetek treningów spontanicznych.';
        }
        if (in_array('fatigue', $risks, true)) {
            $summary[] = 'Częste flagi zmęczenia.';
        }
        if (in_array('inconsistency', $risks, true)) {
            $summary[] = 'Niska regularność treningów.';
        }
        if ($risks === ['none']) {
            $summary[] = 'Brak wyraźnych ryzyk w danych za okno.';
        }

        $questions = [];
        if (in_array('fatigue', $risks, true)) {
            $questions[] = 'Czy odczuwasz zmęczenie lub gorszą regenerację w ostatnim tygodniu?';
        }
        if (in_array('low-compliance', $risks, true)) {
            $questions[] = 'Czy plan powinien lepiej pasować do Twojej dostępności?';
        }
        if (in_array('inconsistency', $risks, true)) {
            $questions[] = 'Ile dni w tygodniu realnie chcesz biegać?';
        }
        if ($questions === []) {
            $questions[] = 'Czy wszystko jest OK z aktualnym obciążeniem?';
        }

        $confidence = $totalSessions < 3 ? 0.4 : 0.6;

        return [
            'generatedAtIso' => $feedback['generatedAtIso'] ?? now()->toISOString(),
            'windowDays' => $windowDays,
            'summary' => array_values(array_slice($summary, 0, 5)),
            'risks' => $risks,
            'questions' => array_values(array_slice($questions, 0, 3)),
            'confidence' => $confidence,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function callOpenAi(array $payload): array
    {
        $apiKey = trim((string) env('OPENAI_API_KEY', ''));
        if ($apiKey === '') {
            $this->observabilityService->warn('insights.openai_missing_api_key');
            return $this->buildStubInsights($payload);
        }

        $model = (string) env('AI_INSIGHTS_MODEL', 'gpt-5-mini');
        $maxTokens = (int) env('AI_INSIGHTS_MAX_OUTPUT_TOKENS', 1200);
        $instructions =
            'Return ONLY valid JSON (no markdown, no prose). The JSON must match this TypeScript type exactly: ' .
            '{"generatedAtIso":string,"windowDays":number,"summary":string[<=5],"risks":("fatigue"|"inconsistency"|"low-compliance"|"none")[],"questions":string[<=3],"confidence":number(0..1)}.';

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'instructions' => $instructions,
                'input' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'max_output_tokens' => $maxTokens,
            ]);

        if (!$response->successful()) {
            $this->observabilityService->warn('insights.openai_http_error', [
                'status' => $response->status(),
            ]);
            return $this->buildStubInsights($payload);
        }

        $outputText = data_get($response->json(), 'output_text');
        if (!is_string($outputText) || trim($outputText) === '') {
            $this->observabilityService->warn('insights.openai_empty_output');
            return $this->buildStubInsights($payload);
        }

        $decoded = json_decode($this->stripMarkdownFences($outputText), true);
        if (!is_array($decoded)) {
            $this->observabilityService->warn('insights.openai_invalid_json');
            return $this->buildStubInsights($payload);
        }

        return $decoded;
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
     * @param array<int,mixed> $risks
     * @return array<int,string>
     */
    private function normalizeRisks(array $risks): array
    {
        $allowed = ['fatigue', 'inconsistency', 'low-compliance', 'none'];
        $normalized = array_values(array_filter(
            array_map(fn ($r) => is_string($r) ? $r : null, $risks),
            fn ($r) => is_string($r) && in_array($r, $allowed, true),
        ));
        return $normalized === [] ? ['none'] : array_values(array_unique($normalized));
    }

    private function normalizeConfidence(mixed $confidence): float
    {
        $value = is_numeric($confidence) ? (float) $confidence : 0.5;
        return max(0.0, min(1.0, $value));
    }
}
