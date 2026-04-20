<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $profile = UserProfile::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'preferred_run_days' => null,
                'preferred_surface' => null,
                'goals' => null,
                'constraints' => null,
            ]
        );

        return response()->json([
            'id' => $profile->id,
            'userId' => $profile->user_id,
            'preferredRunDays' => $profile->preferred_run_days,
            'preferredSurface' => $profile->preferred_surface,
            'goals' => $profile->goals,
            'constraints' => $profile->constraints,
            'createdAt' => $profile->created_at?->toISOString(),
            'updatedAt' => $profile->updated_at?->toISOString(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferredRunDays' => ['sometimes', 'nullable', 'string'],
            'preferredSurface' => ['sometimes', 'nullable', 'string'],
            'goals' => ['sometimes', 'nullable', 'string'],
            'constraints' => ['sometimes', 'nullable', 'string'],
        ]);

        $userId = $this->authUserId($request);
        $profile = UserProfile::query()->firstOrCreate(['user_id' => $userId]);
        if (array_key_exists('preferredRunDays', $validated)) {
            $profile->preferred_run_days = $validated['preferredRunDays'];
        }
        if (array_key_exists('preferredSurface', $validated)) {
            $profile->preferred_surface = $validated['preferredSurface'];
        }
        if (array_key_exists('goals', $validated)) {
            $profile->goals = $validated['goals'];
        }
        if (array_key_exists('constraints', $validated)) {
            $profile->constraints = $validated['constraints'];
        }
        $profile->save();

        return response()->json([
            'id' => $profile->id,
            'userId' => $profile->user_id,
            'preferredRunDays' => $profile->preferred_run_days,
            'preferredSurface' => $profile->preferred_surface,
            'goals' => $profile->goals,
            'constraints' => $profile->constraints,
            'createdAt' => $profile->created_at?->toISOString(),
            'updatedAt' => $profile->updated_at?->toISOString(),
        ]);
    }
}
