<?php

namespace App\Services\Analysis;

class OnboardingSummaryService
{
    public function __construct(private readonly UserTrainingAnalysisCacheService $analysisCache) {}

    /**
     * @return array<string,mixed>
     */
    public function getForUser(int $userId, int $windowDays = 90): array
    {
        $result = $this->analysisCache->getForUser($userId, $windowDays);
        $analysis = $result['analysis'];
        $facts = is_array($analysis['facts'] ?? null) ? $analysis['facts'] : [];
        $hrZones = is_array($analysis['hrZones'] ?? null) ? $analysis['hrZones'] : [];
        $confidence = is_array($analysis['confidence'] ?? null) ? $analysis['confidence'] : [];
        $missingData = is_array($analysis['missingData'] ?? null) ? $analysis['missingData'] : [];
        $planImplications = is_array($analysis['planImplications'] ?? null) ? $analysis['planImplications'] : [];

        return [
            'generatedAtIso' => now()->utc()->toIso8601String(),
            'source' => 'training_analysis',
            'analysisComputedAt' => (string) ($analysis['computedAt'] ?? ''),
            'windowDays' => (int) ($analysis['windowDays'] ?? $windowDays),
            'confidence' => (string) ($confidence['overall'] ?? 'low'),
            'headline' => $this->headline($facts, $confidence),
            'lead' => $this->lead($facts, (int) ($analysis['windowDays'] ?? $windowDays)),
            'highlights' => $this->highlights($facts, $hrZones),
            'badges' => $this->badges($facts, $hrZones, $planImplications),
            'nextSteps' => $this->nextSteps($missingData, $planImplications, $hrZones),
            'analysisCache' => $result['cacheHit'] ? 'hit' : 'miss',
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  array<string,mixed>  $confidence
     */
    private function headline(array $facts, array $confidence): string
    {
        $count = (int) ($facts['workoutCount'] ?? 0);
        $overall = (string) ($confidence['overall'] ?? 'low');

        if ($count === 0) {
            return 'Profil zapisany. Zaczynamy spokojnie.';
        }
        if ($overall === 'high') {
            return 'Mamy solidna baze do personalizacji.';
        }
        if ($count >= 3) {
            return 'Mamy dobry punkt startu do pierwszego planu.';
        }

        return 'Mamy pierwsze sygnaly i bezpieczny start.';
    }

    /**
     * @param  array<string,mixed>  $facts
     */
    private function lead(array $facts, int $windowDays): string
    {
        $count = (int) ($facts['workoutCount'] ?? 0);
        if ($count === 0) {
            return 'Nie widze jeszcze treningow w oknie analizy, wiec plan powinien zaczac od regularnosci i niskiego ryzyka.';
        }

        $count28d = (int) ($facts['workoutCount28d'] ?? 0);
        $lastDays = $facts['lastWorkoutWasDaysAgo'] ?? null;
        $lastText = is_numeric($lastDays) ? ", ostatni {$lastDays} dni temu" : '';

        return "W oknie {$windowDays} dni widze {$count} treningow, w ostatnich 28 dniach {$count28d}{$lastText}.";
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  array<string,mixed>  $hrZones
     * @return list<array{code:string,label:string,value:string,detail:string,tone:string}>
     */
    private function highlights(array $facts, array $hrZones): array
    {
        $workoutCount = (int) ($facts['workoutCount'] ?? 0);
        $count28d = (int) ($facts['workoutCount28d'] ?? 0);
        $longestMeters = $facts['longestRunMeters'] ?? null;
        $load7d = $facts['load7d'] ?? null;
        $consistency = $facts['consistencyScore'] ?? null;
        $hrStatus = (string) ($hrZones['status'] ?? 'missing');

        return [
            [
                'code' => 'workout_count',
                'label' => 'Treningi',
                'value' => (string) $workoutCount,
                'detail' => "{$count28d} w ostatnich 28 dniach",
                'tone' => $workoutCount >= 3 ? 'good' : 'neutral',
            ],
            [
                'code' => 'longest_run',
                'label' => 'Najdluzszy bieg',
                'value' => is_numeric($longestMeters) ? $this->formatKm((float) $longestMeters) : 'brak',
                'detail' => 'z danych w oknie analizy',
                'tone' => is_numeric($longestMeters) ? 'good' : 'neutral',
            ],
            [
                'code' => 'load_7d',
                'label' => 'Load 7d',
                'value' => is_numeric($load7d) ? ((string) round((float) $load7d)).' min' : 'brak',
                'detail' => 'minuty treningowe',
                'tone' => is_numeric($load7d) ? 'good' : 'neutral',
            ],
            [
                'code' => 'consistency',
                'label' => 'Regularnosc',
                'value' => is_numeric($consistency) ? ((string) round((float) $consistency * 100)).'%' : 'brak',
                'detail' => 'udzial aktywnych tygodni',
                'tone' => is_numeric($consistency) && (float) $consistency >= 0.75 ? 'good' : 'neutral',
            ],
            [
                'code' => 'hr_zones',
                'label' => 'Strefy HR',
                'value' => $hrStatus,
                'detail' => $this->hrZoneDetail($hrStatus),
                'tone' => $hrStatus === 'known' ? 'good' : ($hrStatus === 'estimated' ? 'warn' : 'neutral'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  array<string,mixed>  $hrZones
     * @param  list<array<string,mixed>>  $planImplications
     * @return list<array{code:string,label:string,tone:string}>
     */
    private function badges(array $facts, array $hrZones, array $planImplications): array
    {
        $codes = $this->codes($planImplications);
        $count = (int) ($facts['workoutCount'] ?? 0);
        $badges = [];

        $badges[] = $count > 0
            ? ['code' => 'data_seen', 'label' => 'Dane treningowe wykryte', 'tone' => 'good']
            : ['code' => 'cold_start', 'label' => 'Start bez historii treningow', 'tone' => 'neutral'];

        $hrStatus = (string) ($hrZones['status'] ?? 'missing');
        $badges[] = match ($hrStatus) {
            'known' => ['code' => 'hr_known', 'label' => 'Strefy HR z profilu', 'tone' => 'good'],
            'estimated' => ['code' => 'hr_estimated', 'label' => 'Strefy HR oszacowane', 'tone' => 'warn'],
            default => ['code' => 'hr_missing', 'label' => 'Brak stref HR', 'tone' => 'neutral'],
        };

        if (in_array('return_after_break', $codes, true)) {
            $badges[] = ['code' => 'return_after_break', 'label' => 'Powrot po przerwie', 'tone' => 'warn'];
        }
        if (in_array('load_spike', $codes, true)) {
            $badges[] = ['code' => 'load_spike', 'label' => 'Skok obciazenia', 'tone' => 'warn'];
        }

        return $badges;
    }

    /**
     * @param  list<array<string,mixed>>  $missingData
     * @param  list<array<string,mixed>>  $planImplications
     * @param  array<string,mixed>  $hrZones
     * @return list<array{code:string,label:string,reason:string}>
     */
    private function nextSteps(array $missingData, array $planImplications, array $hrZones): array
    {
        $steps = [];
        $missingCodes = $this->codes($missingData);
        $implicationCodes = $this->codes($planImplications);

        if (in_array('no_workouts_in_window', $missingCodes, true)) {
            $steps[] = [
                'code' => 'sync_training_data',
                'label' => 'Dodaj pierwsze treningi',
                'reason' => 'Bez historii plan zacznie bardzo ostroznie.',
            ];
        }

        $hrStatus = (string) ($hrZones['status'] ?? 'missing');
        if ($hrStatus !== 'known') {
            $steps[] = [
                'code' => 'improve_hr_zones',
                'label' => 'Uzupelnij strefy tetna',
                'reason' => $hrStatus === 'estimated'
                    ? 'Sa oszacowane z obserwowanego HR max, wiec nie sa jeszcze precyzyjne.'
                    : 'Brak stref ogranicza planowanie intensywnosci.',
            ];
        }

        if (in_array('return_after_break', $implicationCodes, true)) {
            $steps[] = [
                'code' => 'start_with_return_week',
                'label' => 'Pierwszy tydzien bez mocnych akcentow',
                'reason' => 'Ostatni trening byl dawno, wiec bezpieczniej odbudowac rytm.',
            ];
        }
        if (in_array('load_spike', $implicationCodes, true)) {
            $steps[] = [
                'code' => 'reduce_load',
                'label' => 'Nie dokladaj obciazenia w tym tygodniu',
                'reason' => 'ACWR sygnalizuje skok obciazenia.',
            ];
        }

        if ($steps === []) {
            $steps[] = [
                'code' => 'generate_first_plan',
                'label' => 'Wygeneruj pierwszy plan tygodnia',
                'reason' => 'Dane sa wystarczajace do ostroznej personalizacji.',
            ];
        }

        return array_values(array_slice($steps, 0, 3));
    }

    private function formatKm(float $meters): string
    {
        return number_format($meters / 1000.0, 1, '.', '').' km';
    }

    private function hrZoneDetail(string $status): string
    {
        return match ($status) {
            'known' => 'podane w profilu',
            'estimated' => 'z obserwowanego HR max',
            default => 'do uzupelnienia',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<string>
     */
    private function codes(array $items): array
    {
        return array_values(array_filter(array_map(
            fn (array $item): ?string => is_string($item['code'] ?? null) ? $item['code'] : null,
            $items,
        )));
    }
}
