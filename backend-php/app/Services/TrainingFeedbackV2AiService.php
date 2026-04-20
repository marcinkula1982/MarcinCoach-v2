<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TrainingFeedbackV2AiService
{
    public function __construct(
        private readonly TrainingFeedbackV2Service $feedbackService,
        private readonly AiCacheService $aiCacheService,
        private readonly AiObservabilityService $observabilityService,
    ) {
    }

    /**
     * @return array{answer:string,cache:'hit'|'miss'}|null
     */
    public function answerQuestion(int $feedbackId, int $userId, string $question): ?array
    {
        $record = \App\Models\TrainingFeedbackV2::query()
            ->where('id', $feedbackId)
            ->where('user_id', $userId)
            ->first();
        if (!$record) {
            return null;
        }

        $feedback = $this->feedbackService->getFeedbackForWorkout((int) $record->workout_id, $userId);
        if (!$feedback) {
            return null;
        }

        $normalizedQuestion = trim(preg_replace('/\s+/', ' ', $question) ?? $question);
        $questionHash = substr(hash('sha256', $normalizedQuestion), 0, 24);
        $cached = $this->aiCacheService->get('feedback', $userId, 1);
        if (
            is_array($cached)
            && ($cached['questionHash'] ?? null) === $questionHash
            && (int) ($cached['feedbackId'] ?? 0) === $feedbackId
        ) {
            return ['answer' => (string) ($cached['answer'] ?? ''), 'cache' => 'hit'];
        }

        $provider = strtolower((string) env('AI_FEEDBACK_PROVIDER', 'stub'));
        $answer = $provider === 'openai'
            ? $this->answerWithOpenAi($feedback['feedback'], $normalizedQuestion)
            : $this->buildStubAnswer($feedback['feedback'], $normalizedQuestion);

        $this->aiCacheService->set('feedback', $userId, 1, [
            'answer' => $answer,
            'questionHash' => $questionHash,
            'feedbackId' => $feedbackId,
        ]);

        return ['answer' => $answer, 'cache' => 'miss'];
    }

    /**
     * @param array<string,mixed> $feedback
     */
    private function buildStubAnswer(array $feedback, string $question): string
    {
        $q = mb_strtolower($question);
        if (str_contains($q, 'tętno') || str_contains($q, 'hr')) {
            $stable = (bool) ($feedback['coachSignals']['hrStable'] ?? false);
            return $stable
                ? 'Tętno było stabilne, więc sesja wygląda na dobrze kontrolowaną.'
                : 'Wykryto niestabilność tętna; warto rozważyć lżejszą jednostkę i regenerację.';
        }
        if (str_contains($q, 'obciąż') || str_contains($q, 'load')) {
            $heavy = (bool) ($feedback['coachSignals']['loadHeavy'] ?? false);
            return $heavy
                ? 'To jednostka o relatywnie wysokim obciążeniu, zaplanuj spokojniejszy kolejny dzień.'
                : 'Obciążenie tej jednostki wygląda umiarkowanie.';
        }

        return sprintf(
            'Feedback wskazuje charakter "%s", stabilność tętna: %s, ekonomia: %s.',
            (string) ($feedback['character'] ?? 'easy'),
            (bool) ($feedback['coachSignals']['hrStable'] ?? false) ? 'tak' : 'nie',
            (bool) ($feedback['coachSignals']['economyGood'] ?? false) ? 'dobra' : 'do poprawy',
        );
    }

    /**
     * @param array<string,mixed> $feedback
     */
    private function answerWithOpenAi(array $feedback, string $question): string
    {
        $apiKey = trim((string) env('OPENAI_API_KEY', ''));
        if ($apiKey === '') {
            $this->observabilityService->warn('feedback.openai_missing_api_key');
            return $this->buildStubAnswer($feedback, $question);
        }

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/responses', [
                'model' => (string) env('AI_FEEDBACK_MODEL', 'gpt-4o-mini'),
                'instructions' => 'Jesteś asystentem trenera biegowego. Odpowiadaj krótko i konkretnie po polsku.',
                'input' => "Oto feedback z treningu:\n" . json_encode($feedback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\nPytanie: {$question}",
                'max_output_tokens' => (int) env('AI_FEEDBACK_MAX_OUTPUT_TOKENS', 220),
                'temperature' => 0,
            ]);

        if (!$response->successful()) {
            $this->observabilityService->warn('feedback.openai_http_error', [
                'status' => $response->status(),
            ]);
            return $this->buildStubAnswer($feedback, $question);
        }
        $output = data_get($response->json(), 'output_text');
        if (!is_string($output) || trim($output) === '') {
            $this->observabilityService->warn('feedback.openai_empty_output');
            return $this->buildStubAnswer($feedback, $question);
        }
        return trim($output);
    }
}
