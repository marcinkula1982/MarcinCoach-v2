<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrainingAdjustmentsService;
use App\Services\TrainingContextService;
use App\Services\TrainingFeedbackV2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingAdjustmentsController extends Controller
{
    public function __construct(
        private readonly TrainingContextService $contextService,
        private readonly TrainingAdjustmentsService $adjustmentsService,
        private readonly TrainingFeedbackV2Service $feedbackV2Service,
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

        $result = $this->adjustmentsService->generate($context, $feedbackSignals);
        $result['adjustments'] = array_values(array_map(function (array $adjustment): array {
            unset($adjustment['adaptationType'], $adjustment['confidence'], $adjustment['decisionBasis']);
            return $adjustment;
        }, $result['adjustments'] ?? []));

        return response()->json($result)
            ->header('Cache-Control', 'private, no-cache, must-revalidate');
    }
}
