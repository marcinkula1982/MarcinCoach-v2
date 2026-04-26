<?php

namespace App\Support\Analysis\Dto;

readonly class UserTrainingAnalysisDto
{
    /**
     * @param  array<string,mixed>  $facts
     * @param  array{overall:string,perField:array<string,string>}  $confidence
     * @param  array<int,array{code:string,label:string,blocks:array<int,string>}>  $missingData
     * @param  array<int,array{code:string,severity:string,reason:string,suggestedAction:string|null}>  $planImplications
     */
    public function __construct(
        public string $userId,
        public string $computedAt,
        public int $windowDays,
        public string $serviceVersion,
        public array $facts,
        public HrZonesDto $hrZones,
        public array $confidence,
        public array $missingData,
        public array $planImplications,
    ) {}

    /**
     * @return array{
     *   userId:string,
     *   computedAt:string,
     *   windowDays:int,
     *   serviceVersion:string,
     *   facts:array<string,mixed>,
     *   hrZones:array<string,mixed>,
     *   confidence:array{overall:string,perField:array<string,string>},
     *   missingData:array<int,array{code:string,label:string,blocks:array<int,string>}>,
     *   planImplications:array<int,array{code:string,severity:string,reason:string,suggestedAction:string|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'computedAt' => $this->computedAt,
            'windowDays' => $this->windowDays,
            'serviceVersion' => $this->serviceVersion,
            'facts' => $this->facts,
            'hrZones' => $this->hrZones->toArray(),
            'confidence' => $this->confidence,
            'missingData' => $this->missingData,
            'planImplications' => $this->planImplications,
        ];
    }
}
