<?php

namespace App\Services;

use App\Services\Analysis\UserTrainingAnalysisContextAdapter;
use App\Services\Analysis\UserTrainingAnalysisService;

/**
 * Port of backend/src/training-context/training-context.service.ts.
 * Composes TrainingSignals + UserProfileConstraints into TrainingContext.
 */
class TrainingContextService
{
    public function __construct(
        private readonly TrainingSignalsService $signalsService,
        private readonly UserProfileService $profileService,
        private readonly UserTrainingAnalysisService $analysisService,
        private readonly UserTrainingAnalysisContextAdapter $analysisAdapter,
        private readonly ?BlockPeriodizationService $blockService = null,
        private readonly ?PlanMemoryService $planMemoryService = null,
    ) {}

    /**
     * @return array{
     *   generatedAtIso:string,
     *   windowDays:int,
     *   signals:array<string,mixed>,
     *   profile:array<string,mixed>,
     *   blockContext:array<string,mixed>|null
     * }
     */
    public function getContextForUser(int $userId, int $days = 28): array
    {
        $legacySignals = $this->signalsService->getSignalsForUser($userId, $days);
        try {
            $analysis = $this->analysisService->analyze($userId, $days)->toArray();
            $signals = $this->analysisAdapter->toSignals($analysis, $legacySignals);
        } catch (\Throwable) {
            $signals = $legacySignals;
        }

        $profile = $this->profileService->getConstraintsForUser($userId);

        $blockContext = null;
        if ($this->blockService !== null) {
            $recent = [];
            if ($this->planMemoryService !== null) {
                try {
                    $recent = $this->planMemoryService->getRecentWeeks($userId, 6);
                } catch (\Throwable) {
                    $recent = [];
                }
            }
            try {
                $blockContext = $this->blockService->resolve($profile, $signals, $recent);
            } catch (\Throwable) {
                $blockContext = null;
            }
        }

        return [
            'generatedAtIso' => $signals['windowEnd'] ?? $signals['generatedAtIso'],
            'windowDays' => $days,
            'signals' => $signals,
            'profile' => $profile,
            'blockContext' => $blockContext,
        ];
    }
}
