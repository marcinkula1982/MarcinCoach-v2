<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrainingSignalsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @deprecated F7: kept for the public compatibility endpoint.
 * New backend consumers should use /api/me/training-analysis.
 */
class TrainingSignalsController extends Controller
{
    public function __construct(private readonly TrainingSignalsService $trainingSignalsService) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'numeric', 'min:1', 'max:365'],
        ]);

        $days = isset($validated['days']) ? (int) $validated['days'] : 28;

        $userId = $this->authUserId($request);
        $signals = $this->trainingSignalsService->getSignalsForUser($userId, $days);
        unset($signals['adaptation']);

        return response()->json($signals);
    }
}
