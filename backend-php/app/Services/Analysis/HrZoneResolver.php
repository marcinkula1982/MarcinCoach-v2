<?php

namespace App\Services\Analysis;

use App\Models\UserProfile;
use App\Support\Analysis\Dto\HrZonesDto;
use App\Support\Analysis\Enums\HrZoneStatus;

/**
 * Rozstrzyga status stref HR uzytkownika.
 *
 * Status:
 *  - known    : profil ma komplet hr_z1..hr_z5 (uzytkownik podal),
 *  - estimated: brak profilu, ale w treningach widac max HR -> strefy z procentu max,
 *  - missing  : brak profilu i brak max HR.
 *
 * F3 nie probuje jeszcze 'derived' (LTHR z treningow progowych) - to wymaga
 * dedykowanej detekcji i przyjdzie po F3.
 */
class HrZoneResolver
{
    /**
     * @param  array{minPercent:float,maxPercent:float}[]  $defaultPercentZones
     */
    public function resolve(?UserProfile $profile, ?float $maxHrObserved): HrZonesDto
    {
        $known = $this->fromProfile($profile);
        if ($known !== null) {
            return $known;
        }

        if ($maxHrObserved !== null && $maxHrObserved > 0) {
            return $this->fromObservedMax($maxHrObserved);
        }

        return HrZonesDto::missing();
    }

    private function fromProfile(?UserProfile $profile): ?HrZonesDto
    {
        if ($profile === null) {
            return null;
        }
        $zones = [];
        for ($i = 1; $i <= 5; $i++) {
            $minKey = "hr_z{$i}_min";
            $maxKey = "hr_z{$i}_max";
            $min = $profile->{$minKey} ?? null;
            $max = $profile->{$maxKey} ?? null;
            if (! is_numeric($min) || ! is_numeric($max)) {
                return null;
            }
            $zones[] = [
                'name' => "Z{$i}",
                'minBpm' => (int) $min,
                'maxBpm' => (int) $max,
            ];
        }

        return new HrZonesDto(
            status: HrZoneStatus::Known,
            method: 'user_provided',
            sourceNote: 'Strefy HR podane przez uzytkownika w profilu.',
            zones: $zones,
        );
    }

    /**
     * Procentowe strefy z max HR. To jest grube oszacowanie i ma jawny status
     * 'estimated' - nie jest to test laboratoryjny.
     */
    private function fromObservedMax(float $maxHr): HrZonesDto
    {
        $max = (int) round($maxHr);

        // klasyczne progi: 50/60/70/80/90% HR max
        $bands = [
            ['name' => 'Z1', 'minP' => 0.50, 'maxP' => 0.60],
            ['name' => 'Z2', 'minP' => 0.60, 'maxP' => 0.70],
            ['name' => 'Z3', 'minP' => 0.70, 'maxP' => 0.80],
            ['name' => 'Z4', 'minP' => 0.80, 'maxP' => 0.90],
            ['name' => 'Z5', 'minP' => 0.90, 'maxP' => 1.00],
        ];

        $zones = array_map(function (array $band) use ($max) {
            return [
                'name' => $band['name'],
                'minBpm' => (int) round($max * $band['minP']),
                'maxBpm' => (int) round($max * $band['maxP']),
            ];
        }, $bands);

        return new HrZonesDto(
            status: HrZoneStatus::Estimated,
            method: 'max_hr_percent',
            sourceNote: "Strefy oszacowane z zaobserwowanego max HR = {$max}. To nie jest test progowy - mozliwa korekta po pomiarze LTHR.",
            zones: $zones,
        );
    }
}
