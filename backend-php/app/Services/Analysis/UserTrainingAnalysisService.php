<?php

namespace App\Services\Analysis;

use App\Models\UserProfile;
use App\Models\Workout;
use App\Support\Analysis\Dto\UserTrainingAnalysisDto;
use App\Support\Analysis\Dto\WorkoutFactsDto;
use Carbon\CarbonImmutable;

/**
 * Single source of truth dla migawki stanu zawodnika.
 *
 * Sklada:
 *  - WorkoutFactsExtractor (Workout -> WorkoutFactsDto),
 *  - WorkoutFactsAggregator (lista faktow -> agregaty),
 *  - HrZoneResolver (profil + max HR -> HrZonesDto),
 *  - PlanImplicationsResolver (agregaty + strefy -> sygnaly + confidence).
 *
 * To jest jedyne miejsce, w ktorym dla danego usera powstaje
 * pakiet faktow konsumowany dalej przez plan, alerty, AI i feedback.
 */
class UserTrainingAnalysisService
{
    public const SERVICE_VERSION = '0.4-cross-training';

    public function __construct(
        private readonly WorkoutFactsExtractor $extractor = new WorkoutFactsExtractor,
        private readonly WorkoutFactsAggregator $aggregator = new WorkoutFactsAggregator,
        private readonly HrZoneResolver $hrZoneResolver = new HrZoneResolver,
        private readonly PlanImplicationsResolver $implications = new PlanImplicationsResolver,
    ) {}

    public function analyze(int $userId, int $windowDays = 90): UserTrainingAnalysisDto
    {
        $now = CarbonImmutable::now('UTC');
        $facts = $this->collectFacts($userId, $windowDays, $now);
        $aggregates = $this->aggregator->aggregate($facts, $now);

        $maxHr = $aggregates['maxHrObservedBpm'] ?? null;
        $profile = UserProfile::query()->where('user_id', $userId)->first();
        $hrZones = $this->hrZoneResolver->resolve($profile, is_numeric($maxHr) ? (float) $maxHr : null);

        $implications = $this->implications->resolve($aggregates, $hrZones);

        return new UserTrainingAnalysisDto(
            userId: (string) $userId,
            computedAt: $now->toIso8601String(),
            windowDays: $windowDays,
            serviceVersion: self::SERVICE_VERSION,
            facts: $aggregates,
            hrZones: $hrZones,
            confidence: $implications['confidence'],
            missingData: $implications['missingData'],
            planImplications: $implications['planImplications'],
        );
    }

    /**
     * @return list<WorkoutFactsDto>
     */
    private function collectFacts(int $userId, int $windowDays, CarbonImmutable $now): array
    {
        $threshold = $now->subDays($windowDays);

        $workouts = Workout::query()
            ->where('user_id', $userId)
            ->with('rawTcx')
            ->get();

        $facts = [];
        foreach ($workouts as $workout) {
            $startedRaw = $workout->summary['startTimeIso'] ?? null;
            if (! is_string($startedRaw) || trim($startedRaw) === '') {
                continue;
            }
            try {
                $startedAt = CarbonImmutable::parse($startedRaw)->utc();
            } catch (\Throwable) {
                continue;
            }
            if ($startedAt->lessThan($threshold)) {
                continue;
            }
            $facts[] = $this->extractor->extract($workout);
        }

        return $facts;
    }
}
