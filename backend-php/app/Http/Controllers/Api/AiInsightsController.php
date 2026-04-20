<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiInsightsService;
use App\Services\AiRateLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiInsightsController extends Controller
{
    public function __construct(
        private readonly AiInsightsService $service,
        private readonly AiRateLimitService $rateLimitService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'numeric', 'min:1', 'max:365'],
        ]);

        $days = isset($validated['days']) ? (int) $validated['days'] : 28;
        $userId = $this->authUserId($request);
        $username = (string) $request->header('x-username', 'unknown');
        $limitResult = $this->rateLimitService->consume($userId, 20);
        if (!$limitResult['allowed']) {
            return response()->json([
                'message' => 'AI daily limit exceeded',
                'code' => 'AI_DAILY_LIMIT_EXCEEDED',
            ], 429)->header('x-ai-rate-limit-reset', $limitResult['resetAtIsoUtc']);
        }

        $result = $this->service->getInsightsForUser($userId, $username, $days);

        return response()
            ->json($result)
            ->header('x-ai-cache', $result['cache'])
            ->header('x-ai-rate-limit-limit', (string) $limitResult['limit'])
            ->header('x-ai-rate-limit-used', (string) $limitResult['used'])
            ->header('x-ai-rate-limit-remaining', (string) $limitResult['remaining'])
            ->header('x-ai-rate-limit-reset', $limitResult['resetAtIsoUtc'])
            ->header('Cache-Control', 'private, no-cache, must-revalidate');
    }
}
