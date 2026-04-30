<?php

namespace App\Services;

use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WorkoutPlanFeedbackService
{
    private const RUN_TYPES = ['easy', 'long', 'quality', 'threshold', 'intervals', 'fartlek', 'tempo', 'recovery', 'regeneracja'];
    private const REST_TYPES = ['rest', 'off', 'day_off'];
    private const NON_RUN_SPORTS = ['bike', 'cycling', 'mtb', 'rower', 'swim', 'swimming', 'strength', 'walk_hike', 'other'];

    /**
     * @param array<string,mixed> $legacyFeedback
     * @param array<string,mixed> $compliance
     * @param array<string,mixed> $signals
     * @return array<string,mixed>
     */
    public function build(Workout $workout, array $legacyFeedback = [], array $compliance = [], array $signals = []): array
    {
        $actual = $this->actualWorkout($workout, $legacyFeedback, $compliance);
        $legacyDateRelation = $this->legacyDateRelation($actual['daysFromToday']);
        $dateRelation = $this->publicDateRelation($legacyDateRelation);
        $workoutDataConfidence = $this->workoutDataConfidence($actual);

        $plan = $this->resolvePlan($workout, $actual);
        $assessment = $this->assessPlanMatch($actual, $plan, $legacyDateRelation);
        $missingSessions = $this->missingSessions($workout, $actual);
        $riskFlags = $this->riskFlags($actual, $assessment, $missingSessions, $signals);

        $planImpact = $this->planImpact($assessment, $missingSessions, $riskFlags);
        $nextStep = $this->nextStep($assessment, $missingSessions, $riskFlags);
        $coachFeedback = $this->coachFeedback($actual, $assessment, $planImpact, $nextStep);

        $deviations = array_values(array_unique(array_merge(
            $this->differenceMessages($assessment['planVsExecution']['differences'] ?? []),
            $this->missingSessionMessages($missingSessions),
            $this->riskMessages($riskFlags),
        )));

        return [
            'dateRelation' => $dateRelation,
            'legacyDateRelation' => $legacyDateRelation,
            'planMatchStatus' => $assessment['planMatchStatus'],
            'executionScore' => $assessment['executionScore'],
            'workoutDataConfidence' => $workoutDataConfidence,
            'planMatchConfidence' => $assessment['planMatchConfidence'],
            'confidence' => $assessment['planMatchConfidence'],
            'summaryText' => $assessment['summary'],
            'coachFeedback' => $coachFeedback,
            'planVsExecution' => $assessment['planVsExecution'],
            'praise' => $this->praise($actual, $assessment),
            'deviations' => $deviations,
            'conclusions' => $this->conclusions($assessment, $planImpact, $nextStep),
            'missingSessions' => $missingSessions,
            'riskFlags' => $riskFlags,
            'planImpact' => $planImpact,
            'nextStep' => $nextStep,
            'metrics' => [
                'workoutDate' => $actual['dateIso'],
                'daysFromToday' => $actual['daysFromToday'],
                'dateRelation' => $legacyDateRelation,
                'planSource' => $plan['source'],
                'planWindowStartIso' => $plan['windowStartIso'],
                'planWindowEndIso' => $plan['windowEndIso'],
                'matchedPlanDateIso' => $assessment['matchedPlanDateIso'],
                'durationRatio' => $assessment['durationRatio'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $legacyFeedback
     * @param array<string,mixed> $compliance
     * @return array<string,mixed>
     */
    private function actualWorkout(Workout $workout, array $legacyFeedback, array $compliance): array
    {
        $summary = is_array($workout->summary) ? $workout->summary : [];
        $meta = is_array($workout->workout_meta) ? $workout->workout_meta : [];
        $date = $this->workoutDate($workout);
        $durationSec = (int) ($summary['movingTimeSec'] ?? $summary['durationSec'] ?? $summary['trimmed']['durationSec'] ?? 0);
        $distanceM = (float) ($summary['distanceM'] ?? $summary['trimmed']['distanceM'] ?? 0);
        $pace = is_numeric($summary['avgPaceSecPerKm'] ?? null)
            ? (int) $summary['avgPaceSecPerKm']
            : ($distanceM > 0 && $durationSec > 0 ? (int) round($durationSec / ($distanceM / 1000.0)) : null);
        $sport = $this->normalizeSport($summary['sport'] ?? $summary['sportKind'] ?? $summary['activityType'] ?? $meta['sport'] ?? null);
        $rpe = is_numeric($meta['rpe'] ?? $summary['perceivedEffort'] ?? null)
            ? (int) ($meta['rpe'] ?? $summary['perceivedEffort'])
            : null;

        $intensity = $this->intensityBuckets($summary);
        $durationMin = $durationSec > 0 ? (int) round($durationSec / 60) : null;
        $character = $this->normalizeType((string) ($legacyFeedback['character'] ?? $summary['character'] ?? ''));
        if ($character === 'unknown') {
            $character = $this->actualTypeFromIntensity($intensity, $durationMin, $rpe);
        }

        return [
            'workoutId' => (int) $workout->id,
            'userId' => (int) $workout->user_id,
            'date' => $date,
            'dateIso' => $date?->toDateString(),
            'displayDate' => $date?->format('d.m.Y'),
            'daysFromToday' => $date !== null
                ? (int) Carbon::now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false)
                : null,
            'sport' => $sport,
            'isRun' => $this->isRunSport($sport),
            'type' => $character,
            'durationMin' => $durationMin,
            'durationSec' => $durationSec,
            'distanceKm' => $distanceM > 0 ? round($distanceM / 1000.0, 2) : null,
            'avgPaceSecPerKm' => $pace,
            'rpe' => $rpe,
            'painFlag' => (bool) ($meta['painFlag'] ?? false),
            'hasHrData' => $this->hasHrData($summary),
            'hrStatus' => $compliance['hrStatus'] ?? null,
            'durationStatus' => $compliance['durationStatus'] ?? null,
            'easyBecameZ5' => (bool) ($compliance['easyBecameZ5'] ?? false),
            'highIntensityRatio' => $this->highIntensityRatio($intensity),
            'dataSource' => (string) (($summary['manualCheckIn'] ?? false) ? 'manual_check_in' : ($workout->source ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $actual
     * @return array<string,mixed>
     */
    private function resolvePlan(Workout $workout, array $actual): array
    {
        $direct = $this->directPlannedSession($workout);
        if ($direct !== null) {
            return $this->planFromSessions([$direct], 'workout_meta', $actual, $direct['dateIso'], $direct['dateIso']);
        }

        if (! $actual['date'] instanceof Carbon) {
            return $this->emptyPlan();
        }

        $start = $actual['date']->copy()->subDays(3)->startOfDay();
        $end = $actual['date']->copy()->endOfDay();
        $snapshotPlan = $this->snapshotPlan((int) $workout->user_id, $start, $end, $actual['date']);

        return $snapshotPlan;
    }

    /**
     * @param list<array<string,mixed>> $sessions
     * @param array<string,mixed> $actual
     * @return array<string,mixed>
     */
    private function planFromSessions(array $sessions, string $source, array $actual, ?string $windowStart, ?string $windowEnd): array
    {
        $actualDate = (string) ($actual['dateIso'] ?? '');
        $exact = [];
        $delayed = [];
        foreach ($sessions as $session) {
            $dateIso = (string) ($session['dateIso'] ?? '');
            if ($dateIso === '') {
                continue;
            }
            if ($dateIso === $actualDate) {
                $exact[] = $session;
                continue;
            }
            if ($actual['date'] instanceof Carbon) {
                try {
                    $plannedDate = Carbon::parse($dateIso)->startOfDay();
                    $lag = (int) $plannedDate->diffInDays($actual['date']->copy()->startOfDay(), false);
                    if ($lag >= 1 && $lag <= 3 && $this->isRunningSession($session)) {
                        $delayed[] = $session;
                    }
                } catch (\Throwable) {
                }
            }
        }

        return [
            'hasPlan' => count($sessions) > 0,
            'source' => $source,
            'windowStartIso' => $windowStart,
            'windowEndIso' => $windowEnd,
            'sessions' => $sessions,
            'exactSessions' => $exact,
            'delayedCandidates' => $delayed,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyPlan(): array
    {
        return [
            'hasPlan' => false,
            'source' => null,
            'windowStartIso' => null,
            'windowEndIso' => null,
            'sessions' => [],
            'exactSessions' => [],
            'delayedCandidates' => [],
        ];
    }

    /**
     * @param array<string,mixed> $actual
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function assessPlanMatch(array $actual, array $plan, string $legacyDateRelation): array
    {
        $actualPublic = $this->publicActual($actual);
        $differences = [];
        $planned = null;
        $matchedPlanDateIso = null;
        $durationRatio = null;

        if (! $plan['hasPlan']) {
            $status = $legacyDateRelation === 'historical' ? 'historical' : 'no_plan';
            return $this->assessment(
                $status,
                null,
                'low',
                $status === 'historical'
                    ? 'Trening jest historyczny i nie ma planu obowiązującego dla tej daty.'
                    : 'Dla daty treningu nie ma zapisanego planu, więc nie oceniam wykonania względem założeń.',
                null,
                $actualPublic,
                [],
                null,
                null,
            );
        }

        $exactRun = $this->firstRunningSession($plan['exactSessions']);
        if ($exactRun !== null) {
            $planned = $this->publicPlanned($exactRun);
            $matchedPlanDateIso = (string) $exactRun['dateIso'];
            if (! $actual['isRun']) {
                return $this->assessment(
                    'unplanned',
                    30,
                    'medium',
                    'Na tę datę był zaplanowany bieg, ale zapisany trening ma inną dyscyplinę, więc nie zaliczam go jako wykonania jednostki biegowej.',
                    $planned,
                    $actualPublic,
                    [[
                        'code' => 'wrong_sport',
                        'severity' => 'major',
                        'message' => 'W planie była jednostka biegowa, ale zapisany trening ma inną dyscyplinę: '.$actual['sport'].'.',
                        'planned' => $planned['type'] ?? null,
                        'actual' => $actual['sport'],
                    ]],
                    null,
                    null,
                );
            }
            $comparison = $this->compare($exactRun, $actual);
            $differences = $comparison['differences'];
            $durationRatio = $comparison['durationRatio'];
            $score = $comparison['score'];
            $status = $comparison['matched'] ? 'matched' : 'partial';
            $confidence = $status === 'matched' ? 'high' : 'medium';

            return $this->assessment(
                $status,
                $score,
                $confidence,
                $status === 'matched'
                    ? 'Jednostka z planu na ten dzień została wykonana zgodnie z głównym założeniem.'
                    : 'Jednostka pasuje do daty planu, ale wykonanie różni się od założeń.',
                $planned,
                $actualPublic,
                $differences,
                $matchedPlanDateIso,
                $durationRatio,
            );
        }

        if ($this->hasRunningPlanOnDate($plan['exactSessions']) && ! $actual['isRun']) {
            $plannedRun = $this->firstRunningLikeEvenWrongSport($plan['exactSessions']);
            $planned = $plannedRun !== null ? $this->publicPlanned($plannedRun) : null;
            $differences[] = [
                'code' => 'wrong_sport',
                'severity' => 'major',
                'message' => 'W planie była jednostka biegowa, ale zapisany trening ma inną dyscyplinę: '.$actual['sport'].'.',
                'planned' => $planned['type'] ?? null,
                'actual' => $actual['sport'],
            ];

            return $this->assessment(
                'unplanned',
                35,
                'medium',
                'Data pasuje do planu, ale dyscyplina nie pozwala zaliczyć tego jako treningu biegowego.',
                $planned,
                $actualPublic,
                $differences,
                null,
                null,
            );
        }

        $matchingDelayed = array_values(array_filter(
            $plan['delayedCandidates'],
            fn (array $session): bool => $this->sessionMatchesActual($session, $actual)
        ));
        if (count($matchingDelayed) > 0) {
            $candidate = $matchingDelayed[0];
            $planned = $this->publicPlanned($candidate);
            $comparison = $this->compare($candidate, $actual);
            $differences = $comparison['differences'];
            $differences[] = [
                'code' => 'delayed_execution',
                'severity' => count($matchingDelayed) > 1 ? 'major' : 'minor',
                'message' => count($matchingDelayed) > 1
                    ? 'Trening wygląda jak zaległa jednostka, ale pasuje do więcej niż jednej sesji z ostatnich 3 dni.'
                    : 'Trening wygląda jak prawdopodobne wykonanie jednostki zaplanowanej 1-3 dni wcześniej.',
                'planned' => $candidate['dateIso'],
                'actual' => $actual['dateIso'],
            ];

            return $this->assessment(
                'missed_related',
                max(45, min(75, (int) $comparison['score'] - 10)),
                count($matchingDelayed) > 1 ? 'low' : 'medium',
                'To prawdopodobnie zaległe wykonanie jednostki z planu, ale oznaczam niepewność dopasowania.',
                $planned,
                $actualPublic,
                $differences,
                (string) $candidate['dateIso'],
                $comparison['durationRatio'],
            );
        }

        $unplannedScore = $actual['isRun'] ? ($actual['durationMin'] !== null && $actual['durationMin'] <= 45 ? 55 : 45) : 30;
        return $this->assessment(
            'unplanned',
            $unplannedScore,
            $plan['source'] !== null ? 'medium' : 'low',
            'Na datę treningu nie ma biegowej jednostki do zaliczenia, więc traktuję go jako dodatkowy bodziec.',
            null,
            $actualPublic,
            [],
            null,
            null,
        );
    }

    /**
     * @param array<string,mixed>|null $planned
     * @param array<string,mixed> $actual
     * @param list<array<string,mixed>> $differences
     * @return array<string,mixed>
     */
    private function assessment(
        string $status,
        ?int $score,
        string $confidence,
        string $summary,
        ?array $planned,
        array $actual,
        array $differences,
        ?string $matchedPlanDateIso,
        ?float $durationRatio,
    ): array {
        return [
            'planMatchStatus' => $status,
            'executionScore' => $score === null ? null : max(0, min(100, $score)),
            'planMatchConfidence' => $confidence,
            'summary' => $summary,
            'matchedPlanDateIso' => $matchedPlanDateIso,
            'durationRatio' => $durationRatio,
            'planVsExecution' => [
                'planned' => $planned,
                'actual' => $actual,
                'differences' => $differences,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $planned
     * @param array<string,mixed> $actual
     * @return array{matched:bool,score:int,durationRatio:?float,differences:list<array<string,mixed>>}
     */
    private function compare(array $planned, array $actual): array
    {
        $score = 100;
        $differences = [];
        $durationRatio = null;

        if (! $actual['isRun']) {
            $score -= 50;
            $differences[] = [
                'code' => 'wrong_sport',
                'severity' => 'major',
                'message' => 'To nie jest bieg, więc nie zaliczam go jako wykonania biegowej jednostki planu.',
                'planned' => $planned['type'] ?? null,
                'actual' => $actual['sport'],
            ];
        }

        $plannedType = $this->normalizeType((string) ($planned['type'] ?? ''));
        $actualType = $this->normalizeType((string) ($actual['type'] ?? ''));
        if (! $this->typeMatches($plannedType, $actualType, (int) ($planned['durationMin'] ?? 0), (int) ($actual['durationMin'] ?? 0))) {
            $score -= 30;
            $differences[] = [
                'code' => 'type_mismatch',
                'severity' => 'major',
                'message' => "Charakter jednostki różni się od planu: zaplanowano {$plannedType}, wykonano {$actualType}.",
                'planned' => $plannedType,
                'actual' => $actualType,
            ];
        }

        $plannedDuration = (int) ($planned['durationMin'] ?? 0);
        $actualDuration = (int) ($actual['durationMin'] ?? 0);
        if ($plannedDuration > 0 && $actualDuration > 0) {
            $durationRatio = $actualDuration / $plannedDuration;
            if ($durationRatio < 0.70 || $durationRatio > 1.30) {
                $score -= 25;
                $differences[] = [
                    'code' => $durationRatio < 1 ? 'duration_short_major' : 'duration_long_major',
                    'severity' => 'major',
                    'message' => "Czas mocno odbiegł od planu: {$actualDuration} min zamiast {$plannedDuration} min.",
                    'planned' => $plannedDuration,
                    'actual' => $actualDuration,
                ];
            } elseif ($durationRatio < 0.85 || $durationRatio > 1.15) {
                $score -= 15;
                $differences[] = [
                    'code' => $durationRatio < 1 ? 'duration_short_minor' : 'duration_long_minor',
                    'severity' => 'minor',
                    'message' => "Czas był inny niż w planie: {$actualDuration} min zamiast {$plannedDuration} min.",
                    'planned' => $plannedDuration,
                    'actual' => $actualDuration,
                ];
            }
        }

        $plannedIntensity = strtolower((string) ($planned['intensityHint'] ?? $planned['plannedIntensity'] ?? ''));
        if ($this->isEasyIntensity($plannedIntensity)) {
            $highIntensityRatio = is_numeric($actual['highIntensityRatio'] ?? null) ? (float) $actual['highIntensityRatio'] : 0.0;
            $rpe = is_numeric($actual['rpe'] ?? null) ? (int) $actual['rpe'] : null;
            if (($actual['easyBecameZ5'] ?? false) || $highIntensityRatio > 0.20 || ($rpe !== null && $rpe >= 8)) {
                $score -= 25;
                $differences[] = [
                    'code' => 'intensity_high_major',
                    'severity' => 'major',
                    'message' => 'Intensywność była za wysoka jak na założoną spokojną jednostkę.',
                    'planned' => $plannedIntensity,
                    'actual' => $rpe !== null ? "RPE {$rpe}" : round($highIntensityRatio * 100).'% high intensity',
                ];
            } elseif ($highIntensityRatio > 0.10 || ($rpe !== null && $rpe >= 7)) {
                $score -= 15;
                $differences[] = [
                    'code' => 'intensity_high_minor',
                    'severity' => 'minor',
                    'message' => 'Intensywność była lekko wyższa niż zakładał spokojny bieg.',
                    'planned' => $plannedIntensity,
                    'actual' => $rpe !== null ? "RPE {$rpe}" : round($highIntensityRatio * 100).'% high intensity',
                ];
            }
        }

        return [
            'matched' => $score >= 85 && count(array_filter($differences, fn ($d) => ($d['severity'] ?? '') === 'major')) === 0,
            'score' => max(0, min(100, $score)),
            'durationRatio' => $durationRatio,
            'differences' => $differences,
        ];
    }

    /**
     * @param array<string,mixed> $actual
     * @param array<string,mixed> $assessment
     * @param list<array<string,mixed>> $missingSessions
     * @param array<string,mixed> $signals
     * @return list<array<string,mixed>>
     */
    private function riskFlags(array $actual, array $assessment, array $missingSessions, array $signals): array
    {
        $flags = [];
        if (! $actual['isRun']) {
            $flags[] = [
                'code' => 'WRONG_SPORT',
                'level' => 'medium',
                'message' => 'Dyscyplina nie jest biegiem; nie zaliczam jej jako wykonania planu biegowego.',
            ];
        }
        if (($actual['painFlag'] ?? false) === true) {
            $flags[] = [
                'code' => 'pain_flag',
                'level' => 'high',
                'message' => 'Zgłoszony ból ma pierwszeństwo przed realizacją planu.',
            ];
        }
        if (is_numeric($actual['rpe'] ?? null) && (int) $actual['rpe'] >= 8) {
            $flags[] = [
                'code' => 'high_rpe',
                'level' => 'medium',
                'message' => 'Wysokie RPE podnosi koszt regeneracyjny tej jednostki.',
            ];
        }
        if (($signals['warnings']['overloadRisk'] ?? false) === true) {
            $flags[] = [
                'code' => 'overload_risk',
                'level' => 'high',
                'message' => 'Suma obciążenia sugeruje ostrożność w kolejnych dniach.',
            ];
        }
        if (count($missingSessions) > 0) {
            $flags[] = [
                'code' => 'missing_planned_sessions',
                'level' => 'medium',
                'message' => 'Między znanymi treningami są zaplanowane jednostki bez danych o wykonaniu.',
            ];
        }
        if (($assessment['planMatchStatus'] ?? '') === 'missed_related' && ($assessment['planMatchConfidence'] ?? '') === 'low') {
            $flags[] = [
                'code' => 'ambiguous_plan_match',
                'level' => 'medium',
                'message' => 'Dopasowanie do zaległej jednostki nie jest jednoznaczne.',
            ];
        }

        return $flags;
    }

    /**
     * @param array<string,mixed> $assessment
     * @param list<array<string,mixed>> $missingSessions
     * @param list<array<string,mixed>> $riskFlags
     * @return array{level:string,message:string}
     */
    private function planImpact(array $assessment, array $missingSessions, array $riskFlags): array
    {
        $status = (string) ($assessment['planMatchStatus'] ?? 'unknown');
        $hasHighRisk = count(array_filter($riskFlags, fn ($flag) => ($flag['level'] ?? '') === 'high')) > 0;
        if ($status === 'historical') {
            return [
                'level' => 'none',
                'message' => 'To analiza archiwalna: trening nie zmienia bieżącego mikrocyklu, ale pomaga opisać historię obciążeń.',
            ];
        }
        if ($status === 'no_plan') {
            return [
                'level' => 'none',
                'message' => 'Brak zapisanego planu dla tej daty, więc nie wyciągam decyzji planistycznej z tej jednostki.',
            ];
        }
        if ($hasHighRisk) {
            return [
                'level' => 'high',
                'message' => 'Priorytetem jest ostrożność: kolejna jednostka powinna być lekka lub skrócona, jeśli objawy się utrzymają.',
            ];
        }
        if (count($missingSessions) > 0) {
            return [
                'level' => 'medium',
                'message' => 'Luka w danych wymaga sprawdzenia przed dokładaniem obciążenia; nie zakładam automatycznie, że trening został odpuszczony.',
            ];
        }
        if ($status === 'matched') {
            return [
                'level' => 'low',
                'message' => 'Bodziec z planu został wykonany w założonym zakresie, więc kolejna jednostka może zostać zgodna z harmonogramem.',
            ];
        }
        if ($status === 'partial') {
            return [
                'level' => 'low',
                'message' => 'To była zmieniona wersja jednostki; zwykle nie wymaga nadrabiania, ale warto pilnować celu kolejnego treningu.',
            ];
        }
        if ($status === 'missed_related') {
            return [
                'level' => 'medium',
                'message' => 'Traktuję to jako możliwe zaległe wykonanie, ale z niepewnością; nie dokładaj dodatkowej jednostki tylko po to, by wyrównać kalendarz.',
            ];
        }

        return [
            'level' => 'low',
            'message' => 'Trening był poza planem; sam może być neutralny, ale nie powinien zabierać regeneracji z zaplanowanych jednostek.',
        ];
    }

    /**
     * @param array<string,mixed> $assessment
     * @param list<array<string,mixed>> $missingSessions
     * @param list<array<string,mixed>> $riskFlags
     */
    private function nextStep(array $assessment, array $missingSessions, array $riskFlags): string
    {
        $status = (string) ($assessment['planMatchStatus'] ?? 'unknown');
        $hasPain = count(array_filter($riskFlags, fn ($flag) => ($flag['code'] ?? '') === 'pain_flag')) > 0;
        if ($hasPain) {
            return 'Następny trening wykonaj lekko albo skróć, a przy utrzymującym się bólu odpuść akcent.';
        }
        if ($status === 'historical') {
            return 'Potraktuj ten trening jako element historii obciążeń, nie jako powód do zmiany obecnego planu.';
        }
        if (count($missingSessions) > 0) {
            return 'Jeśli brakująca jednostka była wykonana, zaimportuj ją; jeśli nie, nie nadrabiaj jej na siłę i wróć do planu od najbliższego dnia.';
        }
        if ($status === 'matched') {
            return 'Kolejny trening wykonaj zgodnie z planem, bez dokładania tempa tylko dlatego, że dziś poszło dobrze.';
        }
        if ($status === 'partial') {
            return 'Nie dokładaj brakujących minut jutro; wróć do planu i kolejną spokojną jednostkę wykonaj już w pełnym zakresie.';
        }
        if ($status === 'missed_related') {
            return 'Nie traktuj tego jako sygnału do nadrabiania wszystkiego; ułóż kolejny dzień według aktualnego planu.';
        }
        if ($status === 'unplanned') {
            return 'Nie dokładaj kolejnej jednostki za ten trening; sprawdź najbliższy planowany bieg i zachowaj na niego świeżość.';
        }

        return 'Najpierw wygeneruj lub odśwież plan, a potem oceniaj kolejne treningi względem konkretnych założeń.';
    }

    /**
     * @param array<string,mixed> $actual
     * @param array<string,mixed> $assessment
     * @param array{level:string,message:string} $planImpact
     */
    private function coachFeedback(array $actual, array $assessment, array $planImpact, string $nextStep): string
    {
        $status = (string) ($assessment['planMatchStatus'] ?? 'unknown');
        $done = $this->doneSentence($actual);
        $date = (string) ($actual['displayDate'] ?? 'tej daty');
        $planned = $assessment['planVsExecution']['planned'] ?? null;

        if ($status === 'historical') {
            return "{$done} To jest trening z historii z {$date}, więc nie oceniam go jako wykonania aktualnego planu. Dane treningu są wystarczające do analizy samego wysiłku, ale nie ma podstaw do oceniania go względem obecnego mikrocyklu. Ma sens jako informacja o regularności, obciążeniu i tym, jak wyglądał Twój profil biegania. {$planImpact['message']} Następny krok: {$nextStep}";
        }
        if ($status === 'no_plan') {
            return "{$done} Widzę realny wysiłek, ale dla {$date} nie mam zapisanego planu, do którego mógłbym go uczciwie porównać. To pozwala ocenić obciążenie treningu, lecz nie stopień wykonania założeń. {$planImpact['message']} Następny krok: {$nextStep}";
        }
        if ($status === 'matched') {
            $planText = $this->plannedSentence(is_array($planned) ? $planned : null);
            return "{$done} Ten trening zrealizował założony bodziec: {$planText}. Najważniejsze jest to, że wykonanie pasuje do daty planu i nie wymaga nerwowego dokładania pracy. {$planImpact['message']} Następny krok: {$nextStep}";
        }
        if ($status === 'partial') {
            $diff = $this->firstDifferenceText($assessment['planVsExecution']['differences'] ?? []);
            $planText = $this->plannedSentence(is_array($planned) ? $planned : null);
            return "{$done} To była wartościowa, ale nie pełna realizacja założeń: {$planText}. {$diff} To nie przekreśla jednostki; po prostu zmienia wielkość bodźca i koszt regeneracji. {$planImpact['message']} Następny krok: {$nextStep}";
        }
        if ($status === 'missed_related') {
            $planText = $this->plannedSentence(is_array($planned) ? $planned : null);
            return "{$done} Ten trening najbardziej przypomina zaległą jednostkę: {$planText}. Oznaczam to ostrożnie, bo przesunięcie daty zmienia kontekst tygodnia i nie zawsze da się je jednoznacznie potwierdzić z danych. {$planImpact['message']} Następny krok: {$nextStep}";
        }

        $wrongSport = count(array_filter($assessment['planVsExecution']['differences'] ?? [], fn ($difference) => ($difference['code'] ?? '') === 'wrong_sport')) > 0;
        if ($wrongSport) {
            return "{$done} Na {$date} w planie był bieg, ale ten zapis ma inną dyscyplinę, więc nie wykonuje jednostki biegowej z planu. Może mieć wartość jako obciążenie tlenowe lub ogólne, ale nie zastępuje biegu. {$planImpact['message']} Następny krok: {$nextStep}";
        }

        return "{$done} To był trening poza planem dla {$date}, więc nie liczę go jako wykonania zaplanowanej jednostki. Sam bodziec może mieć sens, szczególnie jeśli był lekki, ale jego wartość zależy od tego, czy nie zabierze jakości z kolejnych dni. {$planImpact['message']} Następny krok: {$nextStep}";
    }

    /**
     * @param array<string,mixed> $actual
     */
    private function doneSentence(array $actual): string
    {
        $duration = is_numeric($actual['durationMin'] ?? null) ? (int) $actual['durationMin'].' min' : 'trening';
        $distance = is_numeric($actual['distanceKm'] ?? null) ? ' / '.round((float) $actual['distanceKm'], 2).' km' : '';
        $type = (string) ($actual['type'] ?? 'trening');

        return "Widzę wykonany wysiłek: {$duration}{$distance}, charakter {$type}.";
    }

    /**
     * @param array<string,mixed>|null $planned
     */
    private function plannedSentence(?array $planned): string
    {
        if ($planned === null) {
            return 'brak jednoznacznej jednostki planu';
        }
        $type = (string) ($planned['type'] ?? 'trening');
        $duration = is_numeric($planned['durationMin'] ?? null) ? (int) $planned['durationMin'].' min' : 'bez czasu';
        $date = (string) ($planned['dateIso'] ?? 'bez daty');
        $intensity = (string) ($planned['intensityHint'] ?? $planned['plannedIntensity'] ?? '');

        return trim("{$type} {$duration} na {$date} {$intensity}");
    }

    /**
     * @param list<array<string,mixed>> $differences
     */
    private function firstDifferenceText(array $differences): string
    {
        if ($differences === []) {
            return 'Różnica jest w kontekście planu, nie w samym fakcie wykonania ruchu.';
        }

        return (string) ($differences[0]['message'] ?? 'Wykonanie odbiegło od założeń.');
    }

    /**
     * @param array<string,mixed> $actual
     * @param array<string,mixed> $assessment
     * @return list<string>
     */
    private function praise(array $actual, array $assessment): array
    {
        $status = (string) ($assessment['planMatchStatus'] ?? 'unknown');
        if ($status === 'matched') {
            return ['Wykonałeś zaplanowany bodziec na datę '.($actual['displayDate'] ?? '').' w zakresie, który zgadza się z planem.'];
        }
        if ($status === 'partial') {
            return ['Mimo odchylenia trening nadal ma wartość, bo dostarczył kontrolowany bodziec zamiast chaotycznego nadrabiania.'];
        }
        if ($status === 'historical') {
            return ['Ten zapis pomaga opisać Twoją historię obciążeń i regularności, bez mieszania go z bieżącym planem.'];
        }
        if ($status === 'missed_related') {
            return ['Wykonany wysiłek wygląda na próbę utrzymania ciągłości mimo przesunięcia daty.'];
        }

        return ['Wysiłek został zapisany i ma znaczenie dla obrazu obciążenia, nawet jeśli nie zalicza konkretnej jednostki planu.'];
    }

    /**
     * @param array<string,mixed> $assessment
     * @param array{level:string,message:string} $planImpact
     * @return list<string>
     */
    private function conclusions(array $assessment, array $planImpact, string $nextStep): array
    {
        return [
            (string) ($assessment['summary'] ?? ''),
            $planImpact['message'],
            'Następny krok: '.$nextStep,
        ];
    }

    /**
     * @param list<array<string,mixed>> $differences
     * @return list<string>
     */
    private function differenceMessages(array $differences): array
    {
        return array_values(array_filter(array_map(
            fn (array $difference): string => (string) ($difference['message'] ?? ''),
            $differences,
        )));
    }

    /**
     * @param list<array<string,mixed>> $missingSessions
     * @return list<string>
     */
    private function missingSessionMessages(array $missingSessions): array
    {
        return array_values(array_map(
            fn (array $session): string => (string) ($session['message'] ?? ''),
            $missingSessions,
        ));
    }

    /**
     * @param list<array<string,mixed>> $riskFlags
     * @return list<string>
     */
    private function riskMessages(array $riskFlags): array
    {
        return array_values(array_filter(array_map(
            fn (array $flag): string => (string) ($flag['message'] ?? ''),
            $riskFlags,
        )));
    }

    /**
     * @param array<string,mixed> $workout
     */
    private function workoutDataConfidence(array $workout): string
    {
        if (! $workout['date'] instanceof Carbon || ! is_numeric($workout['durationMin'] ?? null)) {
            return 'low';
        }
        if (($workout['distanceKm'] ?? null) !== null || ($workout['dataSource'] ?? '') === 'manual_check_in') {
            return 'high';
        }

        return 'medium';
    }

    private function workoutDate(Workout $workout): ?Carbon
    {
        $summary = is_array($workout->summary) ? $workout->summary : [];
        $startTimeIso = $summary['startTimeIso'] ?? null;
        if (is_string($startTimeIso) && $startTimeIso !== '') {
            try {
                return Carbon::parse($startTimeIso);
            } catch (\Throwable) {
            }
        }

        return $workout->created_at instanceof Carbon ? $workout->created_at : null;
    }

    private function legacyDateRelation(?int $daysFromToday): string
    {
        if ($daysFromToday === null) {
            return 'unknown';
        }
        if ($daysFromToday < -3) {
            return 'historical';
        }
        if ($daysFromToday < 0) {
            return 'recent_past';
        }
        if ($daysFromToday === 0) {
            return 'today';
        }

        return 'future';
    }

    private function publicDateRelation(string $legacyDateRelation): string
    {
        return match ($legacyDateRelation) {
            'historical' => 'historical',
            'future' => 'future',
            'unknown' => 'unknown',
            default => 'current',
        };
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,float>
     */
    private function intensityBuckets(array $summary): array
    {
        $raw = is_array($summary['intensityBuckets'] ?? null)
            ? $summary['intensityBuckets']
            : (is_array($summary['intensity'] ?? null) ? $summary['intensity'] : []);

        return [
            'z1Sec' => (float) ($raw['z1Sec'] ?? 0),
            'z2Sec' => (float) ($raw['z2Sec'] ?? 0),
            'z3Sec' => (float) ($raw['z3Sec'] ?? 0),
            'z4Sec' => (float) ($raw['z4Sec'] ?? 0),
            'z5Sec' => (float) ($raw['z5Sec'] ?? 0),
        ];
    }

    /**
     * @param array<string,float> $intensity
     */
    private function highIntensityRatio(array $intensity): float
    {
        $total = array_sum($intensity);
        if ($total <= 0) {
            return 0.0;
        }

        return (($intensity['z4Sec'] ?? 0) + ($intensity['z5Sec'] ?? 0)) / $total;
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function hasHrData(array $summary): bool
    {
        $availability = is_array($summary['dataAvailability'] ?? null) ? $summary['dataAvailability'] : [];
        if (array_key_exists('hr', $availability)) {
            return (bool) $availability['hr'];
        }

        return isset($summary['hr']) || array_sum($this->intensityBuckets($summary)) > 0;
    }

    /**
     * @param array<string,float> $intensity
     */
    private function actualTypeFromIntensity(array $intensity, ?int $durationMin, ?int $rpe): string
    {
        $total = array_sum($intensity);
        if ($rpe !== null && $rpe >= 8) {
            return 'quality';
        }
        if ($total > 0) {
            $z3Up = (($intensity['z3Sec'] ?? 0) + ($intensity['z4Sec'] ?? 0) + ($intensity['z5Sec'] ?? 0)) / $total;
            $z4Up = (($intensity['z4Sec'] ?? 0) + ($intensity['z5Sec'] ?? 0)) / $total;
            if ($z4Up > 0.20) {
                return 'quality';
            }
            if ($z3Up > 0.30) {
                return 'tempo';
            }
        }
        if ($durationMin !== null && $durationMin >= 75) {
            return 'long';
        }

        return 'easy';
    }

    private function normalizeSport(mixed $value): string
    {
        $sport = strtolower(trim((string) ($value ?? 'run')));
        return $sport === '' ? 'run' : $sport;
    }

    private function isRunSport(string $sport): bool
    {
        return ! in_array($sport, self::NON_RUN_SPORTS, true) && ! str_contains($sport, 'bike') && ! str_contains($sport, 'cycle');
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return 'unknown';
        }
        if (str_contains($type, 'interval') || str_contains($type, 'interwa')) {
            return 'intervals';
        }
        if (str_contains($type, 'threshold') || str_contains($type, 'prog')) {
            return 'threshold';
        }
        if (str_contains($type, 'tempo')) {
            return 'tempo';
        }
        if (str_contains($type, 'long') || str_contains($type, 'dlug')) {
            return 'long';
        }
        if (str_contains($type, 'recover') || str_contains($type, 'regener')) {
            return 'recovery';
        }
        if (str_contains($type, 'quality') || str_contains($type, 'akcent')) {
            return 'quality';
        }
        if (in_array($type, self::REST_TYPES, true)) {
            return 'rest';
        }
        if (in_array($type, self::RUN_TYPES, true)) {
            return $type;
        }

        return $type;
    }

    private function typeFamily(string $type): string
    {
        $type = $this->normalizeType($type);
        if (in_array($type, ['easy', 'recovery', 'regeneracja'], true)) {
            return 'easy';
        }
        if ($type === 'long') {
            return 'long';
        }
        if (in_array($type, ['quality', 'threshold', 'intervals', 'fartlek', 'tempo'], true)) {
            return 'quality';
        }

        return $type;
    }

    private function typeMatches(string $plannedType, string $actualType, int $plannedDurationMin, int $actualDurationMin): bool
    {
        $plannedFamily = $this->typeFamily($plannedType);
        $actualFamily = $this->typeFamily($actualType);
        if ($plannedFamily === $actualFamily) {
            return true;
        }
        if ($plannedFamily === 'long' && $actualFamily === 'easy' && $actualDurationMin >= max(60, (int) round($plannedDurationMin * 0.75))) {
            return true;
        }

        return false;
    }

    private function isEasyIntensity(string $intensity): bool
    {
        return $intensity === '' || str_contains($intensity, 'z1') || str_contains($intensity, 'z2') || str_contains($intensity, 'easy');
    }

    /**
     * @param array<string,mixed> $session
     */
    private function isRunningSession(array $session): bool
    {
        $type = $this->normalizeType((string) ($session['type'] ?? ''));
        if (in_array($type, self::REST_TYPES, true) || (int) ($session['durationMin'] ?? 0) <= 0) {
            return false;
        }
        $sport = $this->normalizeSport($session['sportKind'] ?? $session['sport'] ?? 'run');

        return $this->isRunSport($sport);
    }

    /**
     * @param list<array<string,mixed>> $sessions
     */
    private function firstRunningSession(array $sessions): ?array
    {
        foreach ($sessions as $session) {
            if ($this->isRunningSession($session)) {
                return $session;
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $sessions
     */
    private function firstRunningLikeEvenWrongSport(array $sessions): ?array
    {
        foreach ($sessions as $session) {
            $type = $this->normalizeType((string) ($session['type'] ?? ''));
            if (! in_array($type, self::REST_TYPES, true) && (int) ($session['durationMin'] ?? 0) > 0) {
                return $session;
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $sessions
     */
    private function hasRunningPlanOnDate(array $sessions): bool
    {
        return $this->firstRunningLikeEvenWrongSport($sessions) !== null;
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $actual
     */
    private function sessionMatchesActual(array $session, array $actual): bool
    {
        if (! $actual['isRun'] || ! $this->isRunningSession($session)) {
            return false;
        }
        $plannedDuration = (int) ($session['durationMin'] ?? 0);
        $actualDuration = (int) ($actual['durationMin'] ?? 0);
        if ($plannedDuration > 0 && $actualDuration > 0) {
            $ratio = $actualDuration / $plannedDuration;
            if ($ratio < 0.70 || $ratio > 1.30) {
                return false;
            }
        }

        return $this->typeMatches(
            (string) ($session['type'] ?? ''),
            (string) ($actual['type'] ?? ''),
            $plannedDuration,
            $actualDuration,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function directPlannedSession(Workout $workout): ?array
    {
        $summary = is_array($workout->summary) ? $workout->summary : [];
        $meta = is_array($workout->workout_meta) ? $workout->workout_meta : [];
        $planned = is_array($meta['plannedSession'] ?? null)
            ? $meta['plannedSession']
            : (is_array($summary['plannedSession'] ?? null) ? $summary['plannedSession'] : null);
        if (! is_array($planned)) {
            return null;
        }
        $dateIso = $this->dateString(
            $meta['plannedSessionDate']
            ?? $summary['plannedSessionDate']
            ?? $planned['dateIso']
            ?? $planned['dateKey']
            ?? null
        );
        if ($dateIso === null) {
            return null;
        }

        return $this->normalizePlanSession($planned, 'workout_meta', null, $dateIso);
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotPlan(int $userId, Carbon $start, Carbon $end, Carbon $workoutDate): array
    {
        $rows = DB::table('plan_snapshots')
            ->where('user_id', $userId)
            ->where('window_end_iso', '>=', $start->copy()->utc()->toISOString())
            ->where('window_start_iso', '<=', $end->copy()->utc()->toISOString())
            ->orderByDesc('created_at')
            ->get();

        $sessions = [];
        $seen = [];
        $windowStart = null;
        $windowEnd = null;
        foreach ($rows as $row) {
            $snapshot = json_decode((string) $row->snapshot_json, true);
            if (! is_array($snapshot)) {
                continue;
            }
            $rowSessions = $this->sessionsFromSnapshot($snapshot, 'plan_snapshots');
            foreach ($rowSessions as $session) {
                $dateIso = (string) ($session['dateIso'] ?? '');
                if ($dateIso === '') {
                    continue;
                }
                $key = $dateIso.'|'.($session['id'] ?? '').'|'.($session['type'] ?? '').'|'.($session['durationMin'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $sessions[] = $session;
            }
            $windowStart ??= (string) $row->window_start_iso;
            $windowEnd ??= (string) $row->window_end_iso;
        }

        if ($sessions === []) {
            return $this->emptyPlan();
        }

        return $this->planFromSessions($sessions, 'plan_snapshots', [
            'date' => $workoutDate,
            'dateIso' => $workoutDate->toDateString(),
        ], $windowStart, $windowEnd);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return list<array<string,mixed>>
     */
    private function sessionsFromSnapshot(array $snapshot, string $source): array
    {
        $sessions = [];
        $weekStart = $this->carbonOrNull($snapshot['weekStartIso'] ?? $snapshot['windowStartIso'] ?? null);
        $rawSessions = [];
        if (is_array($snapshot['sessions'] ?? null)) {
            $rawSessions = $snapshot['sessions'];
        } elseif (is_array($snapshot['days'] ?? null)) {
            $rawSessions = $snapshot['days'];
        } elseif (is_array($snapshot['items'] ?? null)) {
            $rawSessions = $snapshot['items'];
        } elseif (array_is_list($snapshot)) {
            $rawSessions = $snapshot;
        }

        foreach ($rawSessions as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $session = $this->normalizePlanSession($raw, $source, $weekStart);
            if ($session !== null) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    private function normalizePlanSession(array $raw, string $source, ?Carbon $weekStart = null, ?string $forcedDateIso = null): ?array
    {
        $dateIso = $forcedDateIso
            ?? $this->dateString($raw['dateIso'] ?? $raw['dateKey'] ?? $raw['plannedSessionDate'] ?? null)
            ?? $this->dateString($raw['startTimeIso'] ?? null);
        if ($dateIso === null && $weekStart !== null && isset($raw['day'])) {
            $dateIso = $weekStart->copy()->addDays($this->dayOffset((string) $raw['day']))->toDateString();
        }
        if ($dateIso === null) {
            return null;
        }

        $expectedDurationSec = is_numeric($raw['expectedDurationSec'] ?? null) ? (int) $raw['expectedDurationSec'] : null;
        $durationMin = $this->intOrNull($raw['durationMin'] ?? $raw['plannedDurationMin'] ?? null);
        if ($durationMin === null && $expectedDurationSec !== null) {
            $durationMin = (int) round($expectedDurationSec / 60);
        }
        $type = $this->normalizeType((string) ($raw['type'] ?? $raw['plannedType'] ?? 'easy'));

        return [
            'id' => (string) ($raw['id'] ?? sha1($source.'|'.$dateIso.'|'.$type.'|'.($durationMin ?? 0))),
            'dateIso' => $dateIso,
            'type' => $type,
            'durationMin' => $durationMin ?? 0,
            'distanceKm' => is_numeric($raw['distanceKm'] ?? $raw['plannedDistanceKm'] ?? null)
                ? (float) ($raw['distanceKm'] ?? $raw['plannedDistanceKm'])
                : null,
            'intensityHint' => (string) ($raw['intensityHint'] ?? $raw['plannedIntensity'] ?? ''),
            'sportKind' => (string) ($raw['sportKind'] ?? $raw['sport'] ?? ($type === 'cross_training' ? 'other' : 'run')),
            'structure' => $raw['structure'] ?? null,
            'blocks' => is_array($raw['blocks'] ?? null) ? $raw['blocks'] : null,
            'source' => $source,
        ];
    }

    /**
     * @param array<string,mixed> $actual
     * @return array<string,mixed>
     */
    private function publicActual(array $actual): array
    {
        return [
            'dateIso' => $actual['dateIso'],
            'sport' => $actual['sport'],
            'type' => $actual['type'],
            'durationMin' => $actual['durationMin'],
            'distanceKm' => $actual['distanceKm'],
            'avgPaceSecPerKm' => $actual['avgPaceSecPerKm'],
            'rpe' => $actual['rpe'],
        ];
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private function publicPlanned(array $session): array
    {
        return [
            'id' => $session['id'] ?? null,
            'dateIso' => $session['dateIso'] ?? null,
            'type' => $session['type'] ?? null,
            'durationMin' => $session['durationMin'] ?? null,
            'distanceKm' => $session['distanceKm'] ?? null,
            'intensityHint' => $session['intensityHint'] ?? null,
            'source' => $session['source'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $actual
     * @return list<array<string,mixed>>
     */
    private function missingSessions(Workout $workout, array $actual): array
    {
        if (! $actual['date'] instanceof Carbon || $this->legacyDateRelation($actual['daysFromToday']) === 'historical') {
            return [];
        }
        $previous = $this->previousRunDate($workout, $actual['date']);
        if (! $previous instanceof Carbon) {
            return [];
        }
        $start = $previous->copy()->addDay()->startOfDay();
        $end = $actual['date']->copy()->subDay()->endOfDay();
        if ($start->greaterThan($end)) {
            return [];
        }

        $planned = $this->snapshotPlan((int) $workout->user_id, $start, $end, $actual['date'])['sessions'] ?? [];
        if (! is_array($planned) || $planned === []) {
            return [];
        }
        $actualDates = $this->actualRunDates((int) $workout->user_id, $start, $end);
        $missing = [];
        foreach ($planned as $session) {
            if (! $this->isRunningSession($session)) {
                continue;
            }
            $dateIso = (string) ($session['dateIso'] ?? '');
            if ($dateIso === '' || isset($actualDates[$dateIso])) {
                continue;
            }
            $type = (string) ($session['type'] ?? 'easy');
            $duration = (int) ($session['durationMin'] ?? 0);
            $missing[] = [
                'dateIso' => $dateIso,
                'type' => $type,
                'durationMin' => $duration,
                'status' => 'missing_or_not_imported',
                'message' => "W planie była jednostka {$type} {$duration} min na {$dateIso}, ale nie ma jej w danych; to może być brak importu albo pominięcie.",
            ];
        }

        return $missing;
    }

    private function previousRunDate(Workout $workout, Carbon $currentDate): ?Carbon
    {
        $rows = Workout::query()
            ->where('user_id', $workout->user_id)
            ->where('id', '<>', $workout->id)
            ->orderByDesc('created_at')
            ->limit(80)
            ->get(['id', 'summary', 'created_at']);
        $best = null;
        foreach ($rows as $row) {
            if (! $this->isWorkoutRun($row)) {
                continue;
            }
            $date = $this->workoutDate($row);
            if (! $date instanceof Carbon || ! $date->lessThan($currentDate)) {
                continue;
            }
            if (! $best instanceof Carbon || $date->greaterThan($best)) {
                $best = $date;
            }
        }

        return $best;
    }

    /**
     * @return array<string,bool>
     */
    private function actualRunDates(int $userId, Carbon $start, Carbon $end): array
    {
        $rows = Workout::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'summary', 'created_at']);
        $dates = [];
        foreach ($rows as $row) {
            if (! $this->isWorkoutRun($row)) {
                continue;
            }
            $date = $this->workoutDate($row);
            if (! $date instanceof Carbon || $date->lessThan($start) || $date->greaterThan($end)) {
                continue;
            }
            $dates[$date->toDateString()] = true;
        }

        return $dates;
    }

    private function isWorkoutRun(Workout $workout): bool
    {
        $summary = is_array($workout->summary) ? $workout->summary : [];

        return $this->isRunSport($this->normalizeSport($summary['sport'] ?? $summary['sportKind'] ?? 'run'));
    }

    private function dateString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function carbonOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function dayOffset(string $day): int
    {
        return ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6][$day] ?? 0;
    }
}
