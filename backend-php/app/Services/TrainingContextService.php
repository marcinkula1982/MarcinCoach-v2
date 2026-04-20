<?php

namespace App\Services;

/**
 * Port of backend/src/training-context/training-context.service.ts.
 * Composes TrainingSignals + UserProfileConstraints into TrainingContext.
 */
class TrainingContextService
{
    public function __construct(
        private readonly TrainingSignalsService $signalsService,
        private readonly UserProfileService $profileService,
    ) {
    }

    /**
     * @return array{
     *   generatedAtIso:string,
     *   windowDays:int,
     *   signals:array<string,mixed>,
     *   profile:array<string,mixed>
     * }
     */
    public function getContextForUser(int $userId, int $days = 28): array
    {
        $signals = $this->signalsService->getSignalsForUser($userId, $days);
        $profile = $this->profileService->getConstraintsForUser($userId);

        return [
            // Deterministic: use signals window end (matches Node behaviour)
            'generatedAtIso' => $signals['windowEnd'] ?? $signals['generatedAtIso'],
            'windowDays' => $days,
            'signals' => $signals,
            'profile' => $profile,
        ];
    }
}
