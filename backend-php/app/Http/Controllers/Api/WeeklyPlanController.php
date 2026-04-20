<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrainingAdjustmentsService;
use App\Services\TrainingContextService;
use App\Services\TrainingFeedbackV2Service;
use App\Services\WeeklyPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeeklyPlanController extends Controller
{
    public function __construct(
        private readonly TrainingContextService $contextService,
        private readonly TrainingAdjustmentsService $adjustmentsService,
        private readonly TrainingFeedbackV2Service $feedbackV2Service,
        private readonly WeeklyPlanService $weeklyPlanService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'numeric', 'min:1', 'max:365'],
        ]);

        $days = isset($validated['days']) ? (int) $validated['days'] : 28;
        $userId = $this->authUserId($request);

        $context = $this->contextService->getContextForUser($userId, $days);
        $feedbackSignals = $this->feedbackV2Service->getLatestFeedbackSignalsForUser($userId);
        $adjustments = $this->adjustmentsService->generate($context, $feedbackSignals);
        $plan = $this->weeklyPlanService->generatePlan($context, $adjustments);
        $plan['appliedAdjustmentsCodes'] = array_values(array_map(
            fn ($a) => (string) ($a['code'] ?? ''),
            $adjustments['adjustments'] ?? [],
        ));

        return response()
            ->json($plan)
            ->header('Cache-Control', 'private, no-cache, must-revalidate');
    }
}
