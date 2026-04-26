<?php

namespace App\Support\Analysis\Dto;

use App\Support\Analysis\Enums\HrZoneStatus;

readonly class HrZonesDto
{
    /**
     * @param  array<int,array{name:string,minBpm:int,maxBpm:int}>|null  $zones
     */
    public function __construct(
        public HrZoneStatus $status,
        public string $method,
        public string $sourceNote,
        public ?array $zones,
    ) {}

    public static function missing(string $sourceNote = 'Brak danych do wyznaczenia stref tetna.'): self
    {
        return new self(
            status: HrZoneStatus::Missing,
            method: 'none',
            sourceNote: $sourceNote,
            zones: null,
        );
    }

    /**
     * @return array{
     *   status:string,
     *   method:string,
     *   sourceNote:string,
     *   zones:array<int,array{name:string,minBpm:int,maxBpm:int}>|null
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'method' => $this->method,
            'sourceNote' => $this->sourceNote,
            'zones' => $this->zones,
        ];
    }
}
