<?php

namespace App\Support\Analysis\Dto;

readonly class WorkoutFactsDto
{
    /**
     * @param  array{rawTcxId:string|null,rawFitId:string|null,rawProviderPayloadId:string|null}  $rawProviderRefs
     */
    public function __construct(
        public string $workoutId,
        public string $userId,
        public string $source,
        public ?string $sourceActivityId,
        public string $startedAt,
        public ?int $durationSec,
        public ?int $movingTimeSec,
        public ?float $distanceMeters,
        public string $sportKind,
        public bool $hasGps,
        public bool $hasHr,
        public bool $hasCadence,
        public bool $hasPower,
        public bool $hasElevation,
        public ?float $avgPaceSecPerKm,
        public ?float $avgHrBpm,
        public ?float $maxHrBpm,
        public int $hrSampleCount,
        public ?float $elevationGainMeters,
        public ?int $perceivedEffort,
        public ?string $notes,
        public array $rawProviderRefs,
        public string $computedAt,
        public string $extractorVersion,
    ) {}

    /**
     * @return array{
     *   workoutId:string,
     *   userId:string,
     *   source:string,
     *   sourceActivityId:string|null,
     *   startedAt:string,
     *   durationSec:int|null,
     *   movingTimeSec:int|null,
     *   distanceMeters:float|null,
     *   sportKind:string,
     *   hasGps:bool,
     *   hasHr:bool,
     *   hasCadence:bool,
     *   hasPower:bool,
     *   hasElevation:bool,
     *   avgPaceSecPerKm:float|null,
     *   avgHrBpm:float|null,
     *   maxHrBpm:float|null,
     *   hrSampleCount:int,
     *   elevationGainMeters:float|null,
     *   perceivedEffort:int|null,
     *   notes:string|null,
     *   rawProviderRefs:array{rawTcxId:string|null,rawFitId:string|null,rawProviderPayloadId:string|null},
     *   computedAt:string,
     *   extractorVersion:string
     * }
     */
    public function toArray(): array
    {
        return [
            'workoutId' => $this->workoutId,
            'userId' => $this->userId,
            'source' => $this->source,
            'sourceActivityId' => $this->sourceActivityId,
            'startedAt' => $this->startedAt,
            'durationSec' => $this->durationSec,
            'movingTimeSec' => $this->movingTimeSec,
            'distanceMeters' => $this->distanceMeters,
            'sportKind' => $this->sportKind,
            'hasGps' => $this->hasGps,
            'hasHr' => $this->hasHr,
            'hasCadence' => $this->hasCadence,
            'hasPower' => $this->hasPower,
            'hasElevation' => $this->hasElevation,
            'avgPaceSecPerKm' => $this->avgPaceSecPerKm,
            'avgHrBpm' => $this->avgHrBpm,
            'maxHrBpm' => $this->maxHrBpm,
            'hrSampleCount' => $this->hrSampleCount,
            'elevationGainMeters' => $this->elevationGainMeters,
            'perceivedEffort' => $this->perceivedEffort,
            'notes' => $this->notes,
            'rawProviderRefs' => $this->rawProviderRefs,
            'computedAt' => $this->computedAt,
            'extractorVersion' => $this->extractorVersion,
        ];
    }
}
