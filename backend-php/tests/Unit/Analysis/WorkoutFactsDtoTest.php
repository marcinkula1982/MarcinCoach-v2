<?php

namespace Tests\Unit\Analysis;

use App\Support\Analysis\Dto\WorkoutFactsDto;
use PHPUnit\Framework\TestCase;

class WorkoutFactsDtoTest extends TestCase
{
    public function test_to_array_exposes_provider_neutral_contract_shape(): void
    {
        $dto = new WorkoutFactsDto(
            workoutId: '123',
            userId: '7',
            source: 'garmin',
            sourceActivityId: 'activity-1',
            startedAt: '2026-04-26T10:00:00+00:00',
            durationSec: 3600,
            movingTimeSec: 3500,
            distanceMeters: 10000.0,
            sportKind: 'run',
            hasGps: true,
            hasHr: true,
            hasCadence: false,
            hasPower: false,
            hasElevation: true,
            avgPaceSecPerKm: 360.0,
            avgHrBpm: 142.0,
            maxHrBpm: 178.0,
            hrSampleCount: 600,
            elevationGainMeters: 80.0,
            perceivedEffort: null,
            notes: null,
            rawProviderRefs: [
                'rawTcxId' => 'tcx-1',
                'rawFitId' => null,
                'rawProviderPayloadId' => 'garmin-payload-1',
            ],
            computedAt: '2026-04-26T12:00:00+00:00',
            extractorVersion: '1.0',
        );

        $result = $dto->toArray();

        $this->assertSame('123', $result['workoutId']);
        $this->assertSame('garmin', $result['source']);
        $this->assertSame('run', $result['sportKind']);
        $this->assertTrue($result['hasHr']);
        $this->assertSame(600, $result['hrSampleCount']);
        $this->assertSame('tcx-1', $result['rawProviderRefs']['rawTcxId']);
        $this->assertArrayHasKey('extractorVersion', $result);
    }
}
