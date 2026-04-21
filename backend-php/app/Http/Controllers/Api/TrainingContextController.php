<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrainingContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingContextController extends Controller
{
    public function __construct(private readonly TrainingContextService $contextService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'numeric', 'min:1', 'max:365'],
        ]);

        $days = isset($validated['days']) ? (int) $validated['days'] : 28;
        $userId = $this->authUserId($request);
        $payload = $this->contextService->getContextForUser($userId, $days);
        if (isset($payload['signals']) && is_array($payload['signals'])) {
            unset($payload['signals']['adaptation']);
        }

        return response()->json($payload);
    }
}
