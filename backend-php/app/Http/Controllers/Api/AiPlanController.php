<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiPlanService;
use App\Services\AiRateLimitService;
use App\Services\TrainingAdjustmentsService;
use App\Services\TrainingContextService;
use App\Services\TrainingFeedbackV2Service;
use App\Services\WeeklyPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiPlanController extends Controller
{
    public function __construct(
        private readonly TrainingContextService $contextService,
        private readonly TrainingAdjustmentsService $adjustmentsService,
        private readonly TrainingFeedbackV2Service $feedbackV2Service,
        private readonly WeeklyPlanService $weeklyPlanService,
        private readonly AiPlanService $aiPlanService,
        private readonly AiRateLimitService $rateLimitService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'numeric', 'min:1', 'max:365'],
        ]);
        $days = isset($validated['days']) ? (int) $validated['days'] : 28;
        $userId = $this->authUserId($request);
        $limitResult = $this->rateLimitService->consume($userId, 20);
        if (!$limitResult['allowed']) {
            return response()->json([
                'message' => 'AI daily limit exceeded',
                'code' => 'AI_DAILY_LIMIT_EXCEEDED',
            ], 429)->header('x-ai-rate-limit-reset', $limitResult['resetAtIsoUtc']);
        }

        $context = $this->contextService->getContextForUser($userId, $days);
        $feedbackSignals = $this->feedbackV2Service->getLatestFeedbackSignalsForUser($userId);
        $adjustments = $this->adjustmentsService->generate($context, $feedbackSignals);
        $planBase = $this->weeklyPlanService->generatePlan($context, $adjustments);
        $planBase['appliedAdjustmentsCodes'] = array_values(array_map(
            fn ($a) => (string) ($a['code'] ?? ''),
            $adjustments['adjustments'] ?? [],
        ));

        $result = $this->aiPlanService->buildResponse($userId, $context, $adjustments, $planBase);

        return response()
            ->json($result)
            ->header('x-ai-rate-limit-limit', (string) $limitResult['limit'])
            ->header('x-ai-rate-limit-used', (string) $limitResult['used'])
            ->header('x-ai-rate-limit-remaining', (string) $limitResult['remaining'])
            ->header('x-ai-rate-limit-reset', $limitResult['resetAtIsoUtc'])
            ->header('Cache-Control', 'private, no-cache, must-revalidate');
    }

    public function generate(Request $request): JsonResponse
    {
        return $this->show($request);
    }
}
