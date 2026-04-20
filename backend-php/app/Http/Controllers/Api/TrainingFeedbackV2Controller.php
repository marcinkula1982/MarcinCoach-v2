<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FeedbackSignalsMapper;
use App\Services\AiRateLimitService;
use App\Services\TrainingFeedbackV2AiService;
use App\Services\TrainingFeedbackV2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingFeedbackV2Controller extends Controller
{
    public function __construct(
        private readonly TrainingFeedbackV2Service $service,
        private readonly AiRateLimitService $rateLimitService,
        private readonly TrainingFeedbackV2AiService $aiService,
    ) {
    }

    public function getFeedback(int $workoutId, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $result = $this->service->getFeedbackForWorkout($workoutId, $userId);
        if (!$result) {
            return response()->json(null);
        }
        $feedback = $result['feedback'];
        $feedback['generatedAtIso'] = $result['createdAt'];
        $feedback['coachConclusion'] = $this->buildCoachConclusion($feedback);
        return response()->json([
            'feedbackId' => $result['id'],
            ...$feedback,
        ]);
    }

    public function generate(int $workoutId, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $feedback = $this->service->generateFeedback($workoutId, $userId);
        if (!$feedback) {
            return response()->json(['message' => 'Workout not found'], 404);
        }
        $record = $this->service->getFeedbackForWorkout($workoutId, $userId);
        return response()->json([
            'feedbackId' => $record['id'] ?? null,
            ...$feedback,
            'generatedAtIso' => $record['createdAt'] ?? now()->toISOString(),
            'coachConclusion' => $this->buildCoachConclusion($feedback),
        ]);
    }

    public function getSignals(int $workoutId, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $result = $this->service->getFeedbackForWorkout($workoutId, $userId);
        if (!$result) {
            return response()->json(['message' => 'Feedback not found'], 404);
        }
        return response()->json(FeedbackSignalsMapper::mapFeedbackToSignals($result['feedback']));
    }

    public function answerQuestion(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:1'],
        ]);
        $userId = $this->authUserId($request);
        $limitResult = $this->rateLimitService->consume($userId, 20);
        if (!$limitResult['allowed']) {
            return response()->json([
                'message' => 'AI daily limit exceeded',
                'code' => 'AI_DAILY_LIMIT_EXCEEDED',
            ], 429)->header('x-ai-rate-limit-reset', $limitResult['resetAtIsoUtc']);
        }
        $result = $this->aiService->answerQuestion($id, $userId, (string) $validated['question']);
        if (!$result) {
            return response()->json(['message' => 'Feedback not found'], 404);
        }
        return response()
            ->json(['answer' => $result['answer']])
            ->header('x-ai-rate-limit-limit', (string) $limitResult['limit'])
            ->header('x-ai-rate-limit-used', (string) $limitResult['used'])
            ->header('x-ai-rate-limit-remaining', (string) $limitResult['remaining'])
            ->header('x-ai-rate-limit-reset', $limitResult['resetAtIsoUtc'])
            ->header('x-ai-cache', $result['cache']);
    }

    public function answerViaAi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'feedbackId' => ['required', 'integer', 'min:1'],
            'question' => ['required', 'string', 'min:1'],
        ]);
        $userId = $this->authUserId($request);
        $limitResult = $this->rateLimitService->consume($userId, 20);
        if (!$limitResult['allowed']) {
            return response()->json([
                'message' => 'AI daily limit exceeded',
                'code' => 'AI_DAILY_LIMIT_EXCEEDED',
            ], 429)->header('x-ai-rate-limit-reset', $limitResult['resetAtIsoUtc']);
        }
        $result = $this->aiService->answerQuestion((int) $validated['feedbackId'], $userId, (string) $validated['question']);
        if (!$result) {
            return response()->json(['message' => 'Feedback not found'], 404);
        }
        return response()
            ->json($result)
            ->header('x-ai-rate-limit-limit', (string) $limitResult['limit'])
            ->header('x-ai-rate-limit-used', (string) $limitResult['used'])
            ->header('x-ai-rate-limit-remaining', (string) $limitResult['remaining'])
            ->header('x-ai-rate-limit-reset', $limitResult['resetAtIsoUtc'])
            ->header('x-ai-cache', $result['cache']);
    }

    /**
     * @param array<string,mixed> $feedback
     */
    private function buildCoachConclusion(array $feedback): string
    {
        $parts = [];
        $character = (string) ($feedback['character'] ?? 'easy');
        $parts[] = "Charakter: {$character}";

        $drift = $feedback['hrStability']['drift'] ?? null;
        if (is_numeric($drift)) {
            $parts[] = abs((float) $drift) > 2 ? 'widoczny dryf tętna' : 'stabilne tętno';
        }

        $paceEquality = (float) ($feedback['economy']['paceEquality'] ?? 0);
        $parts[] = $paceEquality > 0.8 ? 'stabilne tempo' : 'zmienne tempo';

        return implode('. ', $parts) . '.';
    }
}
