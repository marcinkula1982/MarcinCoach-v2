<?php

namespace App\Services\Analysis;

use App\Support\Analysis\Dto\HrZonesDto;
use App\Support\Analysis\Enums\Confidence;
use App\Support\Analysis\Enums\HrZoneStatus;

/**
 * Z agregatow + statusu stref HR generuje:
 *  - missingData: czego nie mamy i co przez to jest niepewne,
 *  - planImplications: gotowe sygnaly dla generatora planu,
 *  - confidence: per pole, jak pewne sa liczby.
 *
 * Reguly sa deterministyczne. Nie wolaja AI, nie czytaja bazy.
 */
class PlanImplicationsResolver
{
    /**
     * Prog dni od ostatniego treningu, ktory traktujemy jako 'powrot po przerwie'.
     */
    public const RETURN_AFTER_BREAK_DAYS = 14;

    /**
     * Minimalna liczba treningow w 28d, ponizej ktorej confidence = low.
     */
    public const MIN_WORKOUTS_FOR_HIGH_CONFIDENCE = 8;

    /**
     * @param  array<string,mixed>  $facts
     * @return array{
     *   missingData: list<array{code:string,label:string,blocks:list<string>}>,
     *   planImplications: list<array{code:string,severity:string,reason:string,suggestedAction:string|null}>,
     *   confidence: array{overall:string, perField:array<string,string>},
     * }
     */
    public function resolve(array $facts, HrZonesDto $hrZones): array
    {
        $missing = [];
        $implications = [];

        $count = (int) ($facts['workoutCount'] ?? 0);
        $count28d = (int) ($facts['workoutCount28d'] ?? 0);
        $lastDaysAgo = $facts['lastWorkoutWasDaysAgo'];
        $spike = (bool) ($facts['spikeLoad'] ?? false);
        $acwr = $facts['acwr'] ?? null;
        $consistency = $facts['consistencyScore'] ?? null;

        // 1) brak danych w ogole
        if ($count === 0) {
            $missing[] = [
                'code' => 'no_workouts_in_window',
                'label' => 'Brak treningow w oknie analizy.',
                'blocks' => ['plan_personalization', 'load_history', 'hr_zones'],
            ];
            $implications[] = [
                'code' => 'cold_start',
                'severity' => 'block',
                'reason' => 'Brak danych historycznych - nie mozna spersonalizowac planu poza minimum bezpieczenstwa.',
                'suggestedAction' => 'Zaczac od bardzo lagodnej objetosci i poprosic uzytkownika o synchronizacje danych.',
            ];
        } elseif ($count28d < 3) {
            // 2) za malo treningow w 28d
            $missing[] = [
                'code' => 'sparse_data_28d',
                'label' => 'Mniej niz 3 treningi w ostatnich 28 dniach - dane sa skape.',
                'blocks' => ['load_trend', 'consistency_score'],
            ];
            $implications[] = [
                'code' => 'insufficient_recent_load',
                'severity' => 'warn',
                'reason' => 'Za malo treningow, zeby pewnie wnioskowac o trendzie obciazenia.',
                'suggestedAction' => 'Plan na bazie minimum bezpieczenstwa, bez agresywnych progresji.',
            ];
        }

        // 3) powrot po przerwie
        if ($lastDaysAgo !== null && $lastDaysAgo >= self::RETURN_AFTER_BREAK_DAYS) {
            $implications[] = [
                'code' => 'return_after_break',
                'severity' => 'warn',
                'reason' => "Ostatni trening byl {$lastDaysAgo} dni temu - to powrot po dluzszej przerwie.",
                'suggestedAction' => 'Ograniczyc objetosc do 60-70% poprzedniej i unikac mocnych akcentow przez pierwszy tydzien.',
            ];
        }

        // 4) spike load
        if ($spike && is_numeric($acwr)) {
            $implications[] = [
                'code' => 'load_spike',
                'severity' => 'block',
                'reason' => "ACWR = {$acwr} przekracza prog ostrzegawczy 1.5 - obciazenie tygodniowe wyrasta nad sredniej 4-tygodniowej.",
                'suggestedAction' => 'Zredukowac objetosc o 20-30% w nadchodzacym tygodniu, bez nowych akcentow.',
            ];
        }

        // 5) niska regularnosc
        if (is_numeric($consistency) && (float) $consistency <= 0.5 && $count28d > 0) {
            $implications[] = [
                'code' => 'low_consistency',
                'severity' => 'warn',
                'reason' => 'Regularnosc treningowa w ostatnich 4 tygodniach jest niska.',
                'suggestedAction' => 'Plan z mniejszym spektrem dni, ale stabilnymi godzinami i krotszymi sesjami.',
            ];
        }

        // 6) status stref HR
        $missing = array_merge($missing, $this->hrZoneMissing($hrZones));
        $implications = array_merge($implications, $this->hrZoneImplications($hrZones));

        $confidence = $this->confidence($facts, $hrZones);

        return [
            'missingData' => $missing,
            'planImplications' => $implications,
            'confidence' => $confidence,
        ];
    }

    /**
     * @return list<array{code:string,label:string,blocks:list<string>}>
     */
    private function hrZoneMissing(HrZonesDto $zones): array
    {
        return match ($zones->status) {
            HrZoneStatus::Missing => [[
                'code' => 'no_hr_zones',
                'label' => 'Brak danych do wyznaczenia stref tetna.',
                'blocks' => ['hr_zones', 'intensity_distribution', 'time_in_zone'],
            ]],
            HrZoneStatus::Estimated => [[
                'code' => 'hr_zones_estimated',
                'label' => 'Strefy oszacowane z max HR - moga wymagac korekty.',
                'blocks' => ['precise_intensity_distribution'],
            ]],
            default => [],
        };
    }

    /**
     * @return list<array{code:string,severity:string,reason:string,suggestedAction:string|null}>
     */
    private function hrZoneImplications(HrZonesDto $zones): array
    {
        return match ($zones->status) {
            HrZoneStatus::Missing => [[
                'code' => 'no_zone_basis',
                'severity' => 'warn',
                'reason' => 'Brak stref HR - nie planujemy treningow per strefa.',
                'suggestedAction' => 'Plan w oparciu o czas i tempo, bez celowania w strefe HR.',
            ]],
            HrZoneStatus::Estimated => [[
                'code' => 'zones_estimated_only',
                'severity' => 'info',
                'reason' => 'Strefy oszacowane z max HR - nie sa wynikiem testu progowego.',
                'suggestedAction' => 'Prowadzic strefy ostroznie, nie egzekwowac twardych granic.',
            ]],
            default => [],
        };
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array{overall:string, perField:array<string,string>}
     */
    private function confidence(array $facts, HrZonesDto $hrZones): array
    {
        $count = (int) ($facts['workoutCount'] ?? 0);
        $count28d = (int) ($facts['workoutCount28d'] ?? 0);

        $overall = match (true) {
            $count === 0 => Confidence::Low,
            $count28d >= self::MIN_WORKOUTS_FOR_HIGH_CONFIDENCE => Confidence::High,
            $count28d >= 3 => Confidence::Medium,
            default => Confidence::Low,
        };

        $paceConfidence = $this->confidenceForNumeric($facts['avgPaceSecPerKm'] ?? null, $count28d);
        $hrConfidence = $this->confidenceForNumeric($facts['avgHrBpm'] ?? null, $count28d);
        $loadConfidence = $this->confidenceForNumeric($facts['load7d'] ?? null, $count28d);
        $consistencyConfidence = $this->confidenceForNumeric($facts['consistencyScore'] ?? null, $count28d);

        $hrZoneConfidence = match ($hrZones->status) {
            HrZoneStatus::Known => Confidence::High,
            HrZoneStatus::Derived => Confidence::Medium,
            HrZoneStatus::Estimated => Confidence::Low,
            HrZoneStatus::Missing => Confidence::None,
        };

        return [
            'overall' => $overall->value,
            'perField' => [
                'avgPaceSecPerKm' => $paceConfidence->value,
                'avgHrBpm' => $hrConfidence->value,
                'load7d' => $loadConfidence->value,
                'hrZones' => $hrZoneConfidence->value,
                'consistencyScore' => $consistencyConfidence->value,
            ],
        ];
    }

    private function confidenceForNumeric(mixed $value, int $count28d): Confidence
    {
        if (! is_numeric($value)) {
            return Confidence::None;
        }
        if ($count28d >= self::MIN_WORKOUTS_FOR_HIGH_CONFIDENCE) {
            return Confidence::High;
        }
        if ($count28d >= 3) {
            return Confidence::Medium;
        }

        return Confidence::Low;
    }
}
