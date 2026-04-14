<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrainingFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingFeedbackController extends Controller
{
    public function __construct(private readonly TrainingFeedbackService $trainingFeedbackService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'numeric', 'min:1', 'max:365'],
        ]);

        $days = isset($validated['days']) ? (int) $validated['days'] : 28;

        // Current auth strategy in this backend: default user = 1
        $userId = 1;
        $feedback = $this->trainingFeedbackService->getFeedbackForUser($userId, $days);

        return response()->json($feedback);
    }
}
