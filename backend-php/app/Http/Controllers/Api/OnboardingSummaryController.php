<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analysis\OnboardingSummaryService;
use App\Services\Analysis\UserTrainingAnalysisCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingSummaryController extends Controller
{
    public function __construct(private readonly OnboardingSummaryService $summaryService) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'numeric', 'min:1', 'max:365'],
        ]);

        $days = isset($validated['days']) ? (int) $validated['days'] : 90;
        $payload = $this->summaryService->getForUser($this->authUserId($request), $days);

        return response()
            ->json($payload)
            ->header('Cache-Control', 'private, max-age='.UserTrainingAnalysisCacheService::CACHE_TTL_SECONDS);
    }
}
