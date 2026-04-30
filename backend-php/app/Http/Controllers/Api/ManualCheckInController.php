<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ManualCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManualCheckInController extends Controller
{
    public function store(Request $request, ManualCheckInService $service): JsonResponse
    {
        $validated = $request->validate([
            'plannedSessionDate' => ['required', 'date'],
            'plannedSessionId' => ['nullable', 'string', 'max:191'],
            'status' => ['required', Rule::in(['done', 'completed', 'modified', 'skipped'])],
            'plannedSession' => ['nullable', 'array'],
            'plannedType' => ['nullable', 'string', 'max:64'],
            'plannedDurationMin' => ['nullable', 'integer', 'min:0', 'max:600'],
            'plannedIntensity' => ['nullable', 'string', 'max:64'],
            'actualStartTimeIso' => ['nullable', 'date'],
            'actualDurationMin' => ['nullable', 'integer', 'min:1', 'max:600'],
            'durationMin' => ['nullable', 'integer', 'min:1', 'max:600'],
            'distanceM' => ['nullable', 'numeric', 'min:0', 'max:500000'],
            'distanceKm' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'sport' => ['nullable', 'string', 'max:64'],
            'rpe' => ['nullable', 'integer', 'min:1', 'max:10'],
            'mood' => ['nullable', 'string', 'max:64'],
            'painFlag' => ['nullable', 'boolean'],
            'painNote' => ['nullable', 'string', 'max:1000'],
            'painDescription' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'skipReason' => ['nullable', 'string', 'max:128'],
            'reason' => ['nullable', 'string', 'max:128'],
            'modificationReason' => ['nullable', 'string', 'max:1000'],
            'planModifications' => ['nullable', 'array'],
        ]);

        $result = $service->upsert($this->authUserId($request), $validated);

        return response()->json($result['body'], $result['status']);
    }
}
