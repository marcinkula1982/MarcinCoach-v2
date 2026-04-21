<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlanMemoryService;
use App\Services\TrainingAdjustmentsService;
use App\Services\TrainingAlertsV1Service;
use App\Services\TrainingContextService;
use App\Services\TrainingFeedbackV2Service;
use App\Services\WeeklyPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WeeklyPlanController extends Controller
{
    public function __construct(
        private readonly TrainingContextService $contextService,
        private readonly TrainingAdjustmentsService $adjustmentsService,
        private readonly TrainingFeedbackV2Service $feedbackV2Service,
        private readonly WeeklyPlanService $weeklyPlanService,
        private readonly PlanMemoryService $planMemoryService,
        private readonly TrainingAlertsV1Service $alertsService,
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
        $blockContext = is_array($context['blockContext'] ?? null) ? $context['blockContext'] : null;

        $feedbackSignals = $this->feedbackV2Service->getLatestFeedbackSignalsForUser($userId);
        $adjustments = $this->adjustmentsService->generate($context, $feedbackSignals, $blockContext);
        $plan = $this->weeklyPlanService->generatePlan($context, $adjustments, $blockContext);

        // Zapis pamięci planistycznej + alerty tygodniowe (best-effort).
        try {
            $this->planMemoryService->upsertWeekFromPlan($userId, $plan, $blockContext ?? []);
        } catch (\Throwable $e) {
            try {
                Log::warning('[WeeklyPlanController] upsertWeekFromPlan failed', [
                    'userId' => $userId,
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }

        try {
            $weekStartDate = $this->resolveWeekStartDate($plan);
            if ($weekStartDate !== null) {
                $this->alertsService->upsertWeeklyAlerts($userId, $weekStartDate);
            }
        } catch (\Throwable $e) {
            try {
                Log::warning('[WeeklyPlanController] upsertWeeklyAlerts failed', [
                    'userId' => $userId,
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }

        $plan['sessions'] = array_values(array_map(function (array $session): array {
            unset($session['techniqueFocus'], $session['surfaceHint']);
            return $session;
        }, $plan['sessions'] ?? []));
        $plan['appliedAdjustmentsCodes'] = array_values(array_map(
            fn ($a) => (string) ($a['code'] ?? ''),
            $adjustments['adjustments'] ?? [],
        ));

        return response()
            ->json($plan)
            ->header('Cache-Control', 'private, no-cache, must-revalidate');
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function resolveWeekStartDate(array $plan): ?string
    {
        $iso = $plan['weekStartIso'] ?? null;
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($iso)->startOfDay()->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
