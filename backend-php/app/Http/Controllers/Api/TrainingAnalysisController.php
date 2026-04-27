<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analysis\UserTrainingAnalysisCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingAnalysisController extends Controller
{
    public function __construct(private readonly UserTrainingAnalysisCacheService $analysisCache) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'numeric', 'min:1', 'max:365'],
        ]);

        $days = isset($validated['days']) ? (int) $validated['days'] : 90;
        $result = $this->analysisCache->getForUser($this->authUserId($request), $days);

        return response()
            ->json($result['analysis'])
            ->header('Cache-Control', 'private, max-age='.UserTrainingAnalysisCacheService::CACHE_TTL_SECONDS)
            ->header('X-Training-Analysis-Cache', $result['cacheHit'] ? 'hit' : 'miss');
    }
}
