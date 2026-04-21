<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * M3/M4 beyond current scope — Etap B.
 *
 * Serwis czysto deterministyczny. Bez DB. Decyduje:
 *  - block_type           : base | build | peak | taper | recovery | return
 *  - block_goal           : tekst opisowy celu bloku
 *  - week_role            : build | peak | recovery | taper | test
 *  - load_direction       : increase | maintain | decrease
 *  - key_capability_focus : aerobic_base | threshold | vo2max | long_run | economy
 *
 * Kolejność reguł (pierwsza dopasowana wygrywa dla block_type):
 *   1) weeksUntilRace <= 2              → taper
 *   2) weeksUntilRace <= 6              → peak
 *   3) returnAfterBreak OR injuryRisk   → return
 *   4) postRaceWeek                     → recovery
 *   5) rolling4wLoad rośnie 3 tyg.      → build
 *   6) fallback                         → base
 *
 * Rola tygodnia:
 *   - Co 4. tydzień w bloku (poza peak/taper) → recovery
 *   - base/build                             → build
 *   - peak                                   → peak
 *   - taper                                  → taper
 *   - recovery/return                        → recovery
 *
 * load_direction:
 *   - week_role=recovery/taper              → decrease
 *   - ostatni tydzień actual < 85% planned  → maintain
 *   - inaczej                                → increase
 */
class BlockPeriodizationService
{
    public const BLOCK_BASE = 'base';
    public const BLOCK_BUILD = 'build';
    public const BLOCK_PEAK = 'peak';
    public const BLOCK_TAPER = 'taper';
    public const BLOCK_RECOVERY = 'recovery';
    public const BLOCK_RETURN = 'return';

    public const ROLE_BUILD = 'build';
    public const ROLE_PEAK = 'peak';
    public const ROLE_RECOVERY = 'recovery';
    public const ROLE_TAPER = 'taper';
    public const ROLE_TEST = 'test';

    public const DIRECTION_INCREASE = 'increase';
    public const DIRECTION_MAINTAIN = 'maintain';
    public const DIRECTION_DECREASE = 'decrease';

    public const FOCUS_AEROBIC_BASE = 'aerobic_base';
    public const FOCUS_THRESHOLD = 'threshold';
    public const FOCUS_VO2MAX = 'vo2max';
    public const FOCUS_LONG_RUN = 'long_run';
    public const FOCUS_ECONOMY = 'economy';

    /**
     * @param array<string,mixed> $profile   UserProfile shape (jak z UserProfileService)
     * @param array<string,mixed> $signals   TrainingSignals shape (jak z TrainingSignalsService)
     * @param array<int,array<string,mixed>> $recentWeeks ostatnie N tygodni
     *                                                    malejąco po week_start_date
     *
     * @return array{
     *   block_type:string,
     *   block_goal:string,
     *   week_role:string,
     *   load_direction:string,
     *   key_capability_focus:string,
     *   weeks_in_block:int,
     *   weeks_until_race:int|null,
     *   rationale:string
     * }
     */
    public function resolve(array $profile, array $signals, array $recentWeeks = []): array
    {
        $weeksUntilRace = $this->computeWeeksUntilRace($profile, $signals);
        $returnAfterBreak = (bool) ($signals['flags']['injuryRisk'] ?? false);
        // injuryRisk flag w obecnym TrainingSignalsService łączy returnAfterBreak i postRaceWeek.
        // Osobny postRaceWeek odczytujemy z adaptation jeśli dostępny.
        $postRaceWeek = (bool) ($signals['adaptation']['postRaceWeek'] ?? $signals['postRaceWeek'] ?? false);
        $hasCurrentPain = (bool) ($profile['health']['hasCurrentPain'] ?? false);
        $rollingLoadTrend = $this->computeLoadTrend($recentWeeks);

        [$blockType, $blockGoal, $rationale] = $this->resolveBlockType(
            $weeksUntilRace,
            $returnAfterBreak,
            $postRaceWeek,
            $hasCurrentPain,
            $rollingLoadTrend,
        );

        $weeksInBlock = $this->computeWeeksInBlock($recentWeeks, $blockType);
        $weekRole = $this->resolveWeekRole($blockType, $weeksInBlock);
        $loadDirection = $this->resolveLoadDirection($weekRole, $recentWeeks);
        $focus = $this->resolveKeyCapabilityFocus($blockType, $weeksUntilRace, $recentWeeks);

        return [
            'block_type' => $blockType,
            'block_goal' => $blockGoal,
            'week_role' => $weekRole,
            'load_direction' => $loadDirection,
            'key_capability_focus' => $focus,
            'weeks_in_block' => $weeksInBlock,
            'weeks_until_race' => $weeksUntilRace,
            'rationale' => $rationale,
        ];
    }

    private function computeWeeksUntilRace(array $profile, array $signals): ?int
    {
        $raceDateStr = $profile['primaryRace']['date'] ?? null;
        if (!is_string($raceDateStr) || $raceDateStr === '') {
            return null;
        }

        try {
            $raceDate = CarbonImmutable::parse($raceDateStr)->startOfDay();
            $anchorIso = $signals['windowEnd'] ?? $signals['generatedAtIso'] ?? null;
            $anchor = is_string($anchorIso) && $anchorIso !== ''
                ? CarbonImmutable::parse($anchorIso)->startOfDay()
                : CarbonImmutable::now('UTC')->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($raceDate->lessThan($anchor)) {
            return null;
        }

        $days = $anchor->diffInDays($raceDate);
        return (int) floor($days / 7);
    }

    /**
     * @return 'up'|'down'|'flat'
     */
    private function computeLoadTrend(array $recentWeeks): string
    {
        // 3 tygodnie z rzędu rosnące planned_total_min → up
        if (count($recentWeeks) < 3) {
            return 'flat';
        }
        $last3 = array_slice($recentWeeks, 0, 3);
        // malejąco po dacie — 0 = najnowszy; chcemy rosnący trend w czasie → starszy < nowszy
        $w0 = (int) ($last3[0]['planned_total_min'] ?? 0);
        $w1 = (int) ($last3[1]['planned_total_min'] ?? 0);
        $w2 = (int) ($last3[2]['planned_total_min'] ?? 0);
        if ($w0 <= 0 || $w1 <= 0 || $w2 <= 0) {
            return 'flat';
        }
        if ($w0 > $w1 && $w1 > $w2) {
            return 'up';
        }
        if ($w0 < $w1 && $w1 < $w2) {
            return 'down';
        }
        return 'flat';
    }

    /**
     * @return array{0:string,1:string,2:string}  [block_type, block_goal, rationale]
     */
    private function resolveBlockType(
        ?int $weeksUntilRace,
        bool $returnAfterBreak,
        bool $postRaceWeek,
        bool $hasCurrentPain,
        string $loadTrend,
    ): array {
        if ($weeksUntilRace !== null && $weeksUntilRace <= 2) {
            return [
                self::BLOCK_TAPER,
                'Taper przed startem docelowym',
                'weeksUntilRace <= 2 → taper',
            ];
        }
        if ($weeksUntilRace !== null && $weeksUntilRace <= 6) {
            return [
                self::BLOCK_PEAK,
                'Szczyt formy przed startem docelowym',
                'weeksUntilRace <= 6 → peak',
            ];
        }
        if ($returnAfterBreak || $hasCurrentPain) {
            return [
                self::BLOCK_RETURN,
                'Bezpieczny powrót do treningu po przerwie / urazie',
                $hasCurrentPain
                    ? 'hasCurrentPain=true → return'
                    : 'injuryRisk/returnAfterBreak → return',
            ];
        }
        if ($postRaceWeek) {
            return [
                self::BLOCK_RECOVERY,
                'Regeneracja po starcie',
                'postRaceWeek=true → recovery',
            ];
        }
        if ($loadTrend === 'up') {
            return [
                self::BLOCK_BUILD,
                'Rozbudowa bazy tlenowej i objętości',
                'load rośnie 3 tygodnie z rzędu → build',
            ];
        }
        return [
            self::BLOCK_BASE,
            'Baza tlenowa i rutyna tygodnia',
            'brak sygnałów na zmianę → base',
        ];
    }

    private function computeWeeksInBlock(array $recentWeeks, string $blockType): int
    {
        $count = 1; // ten tydzień
        foreach ($recentWeeks as $w) {
            if (($w['block_type'] ?? null) === $blockType) {
                $count++;
                continue;
            }
            break;
        }
        return $count;
    }

    private function resolveWeekRole(string $blockType, int $weeksInBlock): string
    {
        if ($blockType === self::BLOCK_TAPER) {
            return self::ROLE_TAPER;
        }
        if ($blockType === self::BLOCK_PEAK) {
            return self::ROLE_PEAK;
        }
        if ($blockType === self::BLOCK_RECOVERY || $blockType === self::BLOCK_RETURN) {
            return self::ROLE_RECOVERY;
        }
        // base / build — co 4. tydzień recovery
        if ($weeksInBlock > 0 && $weeksInBlock % 4 === 0) {
            return self::ROLE_RECOVERY;
        }
        return self::ROLE_BUILD;
    }

    private function resolveLoadDirection(string $weekRole, array $recentWeeks): string
    {
        if ($weekRole === self::ROLE_RECOVERY || $weekRole === self::ROLE_TAPER) {
            return self::DIRECTION_DECREASE;
        }

        // Oceń ostatni tydzień: actual vs planned
        if (!empty($recentWeeks)) {
            $last = $recentWeeks[0];
            $planned = (int) ($last['planned_total_min'] ?? 0);
            $actual = (int) ($last['actual_total_min'] ?? 0);
            if ($planned > 0) {
                $ratio = $actual / $planned;
                if ($ratio < 0.85) {
                    return self::DIRECTION_MAINTAIN;
                }
            }
        }

        return self::DIRECTION_INCREASE;
    }

    private function resolveKeyCapabilityFocus(string $blockType, ?int $weeksUntilRace, array $recentWeeks): string
    {
        if ($blockType === self::BLOCK_TAPER) {
            return self::FOCUS_ECONOMY;
        }
        if ($blockType === self::BLOCK_PEAK) {
            return self::FOCUS_VO2MAX;
        }
        if ($blockType === self::BLOCK_RETURN || $blockType === self::BLOCK_RECOVERY) {
            return self::FOCUS_AEROBIC_BASE;
        }
        if ($blockType === self::BLOCK_BUILD) {
            // Preferuj threshold jeśli blok base dłużej niż 3 tygodnie
            $baseWeeks = 0;
            foreach ($recentWeeks as $w) {
                if (($w['block_type'] ?? null) === self::BLOCK_BASE) {
                    $baseWeeks++;
                    continue;
                }
                break;
            }
            if ($baseWeeks >= 3) {
                return self::FOCUS_THRESHOLD;
            }
            return self::FOCUS_AEROBIC_BASE;
        }
        // base default
        return self::FOCUS_AEROBIC_BASE;
    }
}
