<?php

namespace App\Services;

use App\Models\TrainingFeedbackV2;
use App\Models\Workout;
use App\Services\Analysis\UserTrainingAnalysisService;
use App\Services\Analysis\WorkoutFactsExtractor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Slim port of Node TrainingFeedbackV2Service — only the pieces needed by
 * TrainingAdjustmentsService: getLatestFeedbackSignalsForUser.
 *
 * Feedback records are expected to already exist in the training_feedback_v2
 * table (written by a future generateFeedback port or by the Node side).
 */
class TrainingFeedbackV2Service
{
    public function __construct(
        private readonly ?UserTrainingAnalysisService $analysisService = null,
        private readonly ?WorkoutFactsExtractor $factsExtractor = null,
    ) {}

    /**
     * @return array{id:int,feedback:array<string,mixed>,createdAt:string}|null
     */
    public function getFeedbackForWorkout(int $workoutId, int $userId): ?array
    {
        $record = TrainingFeedbackV2::query()
            ->where('workout_id', $workoutId)
            ->where('user_id', $userId)
            ->first();
        if (! $record) {
            return null;
        }

        $feedback = $this->parseAndNormalize($record->feedback);
        if ($feedback === null) {
            return null;
        }

        return [
            'id' => (int) $record->id,
            'feedback' => $feedback,
            'createdAt' => $record->created_at?->toISOString() ?? now()->toISOString(),
        ];
    }

    /**
     * Deterministic lightweight feedback generation for PHP stack parity.
     *
     * @return array<string,mixed>|null
     */
    public function generateFeedback(int $workoutId, int $userId): ?array
    {
        $workout = Workout::query()
            ->where('id', $workoutId)
            ->where('user_id', $userId)
            ->first();
        if (! $workout) {
            return null;
        }

        $facts = ($this->factsExtractor ?? app(WorkoutFactsExtractor::class))->extract($workout);
        $analysisFacts = $this->analysisFactsForUser($userId);

        $summary = is_array($workout->summary) ? $workout->summary : [];
        $durationSec = (float) ($facts->durationSec ?? 0);
        $weeklyLoadContribution = $durationSec > 0 ? round($durationSec / 60.0, 2) : 0.0;
        $analysisLoad7d = $this->numberOrNull($analysisFacts['load7d'] ?? null);
        $analysisAcwr = $this->numberOrNull($analysisFacts['acwr'] ?? null);
        $analysisSpikeLoad = (bool) ($analysisFacts['spikeLoad'] ?? false);

        $intensity = [];
        if (is_array($summary['intensityBuckets'] ?? null)) {
            $intensity = $summary['intensityBuckets'];
        } elseif (is_array($summary['intensity'] ?? null)) {
            $intensity = $summary['intensity'];
        }

        $z1 = (float) ($intensity['z1Sec'] ?? 0);
        $z2 = (float) ($intensity['z2Sec'] ?? 0);
        $z3 = (float) ($intensity['z3Sec'] ?? 0);
        $z4 = (float) ($intensity['z4Sec'] ?? 0);
        $z5 = (float) ($intensity['z5Sec'] ?? 0);
        $intensityScore = $z1 * 1 + $z2 * 2 + $z3 * 3 + $z4 * 4 + $z5 * 5;

        $character = $this->determineCharacter(
            ['z1Sec' => $z1, 'z2Sec' => $z2, 'z3Sec' => $z3, 'z4Sec' => $z4, 'z5Sec' => $z5],
            $durationSec,
        );

        $hrDrift = is_numeric($summary['hrDrift'] ?? null) ? (float) $summary['hrDrift'] : null;
        $hrStable = ($hrDrift === null || abs($hrDrift) <= 2.0);
        $paceEquality = is_numeric($summary['paceEquality'] ?? null)
            ? max(0.0, min(1.0, (float) $summary['paceEquality']))
            : 0.75;

        $feedback = [
            'character' => $character,
            'hrStability' => [
                'drift' => $hrDrift,
                'artefacts' => false,
            ],
            'economy' => [
                'paceEquality' => $paceEquality,
                'variance' => (float) round((1 - $paceEquality) * 100, 2),
            ],
            'loadImpact' => [
                'weeklyLoadContribution' => $weeklyLoadContribution,
                'analysisLoad7d' => $analysisLoad7d,
                'acwr' => $analysisAcwr,
                'spikeLoad' => $analysisSpikeLoad,
                'intensityScore' => (float) round($intensityScore, 2),
            ],
            'coachSignals' => [
                'character' => $character,
                'hrStable' => $hrStable,
                'economyGood' => $paceEquality > 0.8,
                'loadHeavy' => $weeklyLoadContribution > 50 || $analysisSpikeLoad,
            ],
            'metrics' => [
                'hrDrift' => $hrDrift,
                'paceEquality' => $paceEquality,
                'weeklyLoadContribution' => $weeklyLoadContribution,
                'analysisLoad7d' => $analysisLoad7d,
                'acwr' => $analysisAcwr,
                'spikeLoad' => $analysisSpikeLoad,
            ],
            'workoutId' => $workoutId,
        ];

        TrainingFeedbackV2::query()->updateOrCreate(
            ['workout_id' => $workoutId],
            [
                'user_id' => $userId,
                'feedback' => $feedback,
            ]
        );

        return $feedback;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getProductFeedbackForWorkout(int $workoutId, int $userId, bool $generateIfMissing = false): ?array
    {
        $record = $this->getFeedbackForWorkout($workoutId, $userId);
        if ($record === null && $generateIfMissing) {
            $this->generateFeedback($workoutId, $userId);
            $record = $this->getFeedbackForWorkout($workoutId, $userId);
        }
        if ($record === null) {
            return null;
        }

        $workout = Workout::query()
            ->where('id', $workoutId)
            ->where('user_id', $userId)
            ->first();
        if (!$workout) {
            return null;
        }

        return $this->buildProductFeedback((int) $record['id'], $record['feedback'], $record['createdAt'], $workout);
    }

    /**
     * @param array<string,mixed> $feedback
     * @return array<string,mixed>
     */
    private function buildProductFeedback(int $feedbackId, array $feedback, string $createdAt, Workout $workout): array
    {
        $summary = is_array($workout->summary) ? $workout->summary : [];
        $meta = is_array($workout->workout_meta) ? $workout->workout_meta : [];
        $durationSec = (int) ($summary['movingTimeSec'] ?? $summary['durationSec'] ?? $summary['trimmed']['durationSec'] ?? 0);
        $distanceM = (float) ($summary['distanceM'] ?? $summary['trimmed']['distanceM'] ?? 0);
        $pace = is_numeric($summary['avgPaceSecPerKm'] ?? null)
            ? (int) $summary['avgPaceSecPerKm']
            : ($distanceM > 0 && $durationSec > 0 ? (int) round($durationSec / ($distanceM / 1000.0)) : null);
        $dataAvailability = is_array($summary['dataAvailability'] ?? null) ? $summary['dataAvailability'] : [];
        $isManualCheckIn = (bool) ($summary['manualCheckIn'] ?? false) || (($meta['dataSource'] ?? null) === 'manual_check_in');
        $hasHrData = array_key_exists('hr', $dataAvailability)
            ? (bool) $dataAvailability['hr']
            : isset($summary['hr']);
        $hasPaceData = $distanceM > 0 && $durationSec > 0 && $pace !== null;
        $rpe = is_numeric($meta['rpe'] ?? null) ? (int) $meta['rpe'] : null;
        $painFlag = (bool) ($meta['painFlag'] ?? false);

        $compliance = $this->loadCompliance($workout->id);
        $signals = FeedbackSignalsMapper::mapFeedbackToSignals($feedback);
        $praise = [];
        $deviations = [];
        $conclusions = [];

        if (($compliance['durationStatus'] ?? null) === 'OK') {
            $praise[] = 'Dobra robota: czas treningu byl zgodny z zalozeniem.';
        } elseif (($compliance['durationStatus'] ?? null) === 'MINOR_DEVIATION') {
            $deviations[] = 'Czas treningu lekko odbiegal od planu.';
        } elseif (($compliance['durationStatus'] ?? null) === 'MAJOR_DEVIATION') {
            $deviations[] = 'Czas treningu mocno odbiegal od planu.';
        }

        $planCompliance = (string) ($meta['planCompliance'] ?? 'unknown');
        if ($planCompliance === 'planned') {
            $praise[] = 'Plus za trzymanie sie planu i wykonanie zaplanowanej jednostki.';
        } elseif ($planCompliance === 'modified') {
            $praise[] = 'Dobrze, ze trening zostal dopasowany zamiast nadrabiany na sile.';
        } elseif ($planCompliance === 'unplanned') {
            $praise[] = 'Spontaniczny ruch tez buduje baze, o ile nie rozbija regeneracji.';
        }

        if ($hasHrData) {
            if (($feedback['coachSignals']['hrStable'] ?? false) === true) {
                $praise[] = 'Tetno bylo stabilne wzgledem charakteru wysilku.';
            } else {
                $deviations[] = 'Tetno wygladalo mniej stabilnie - mozliwy dryf, zmeczenie albo warunki dnia.';
            }
        }

        if ($hasPaceData) {
            if (($feedback['coachSignals']['economyGood'] ?? false) === true) {
                $praise[] = 'Tempo bylo rowne, co jest dobrym sygnalem kontroli.';
            } else {
                $deviations[] = 'Tempo bylo zmienne; warto sprawdzic trase, wiatr, przewyzszenia albo RPE.';
            }
        }

        if (($compliance['easyBecameZ5'] ?? false) === true) {
            $deviations[] = 'Jednostka easy weszla w Z5 - to wazne odchylenie od zalozen.';
        } elseif ($hasHrData && ($compliance['hrStatus'] ?? null) === 'OK') {
            $praise[] = 'Intensywnosc tetna miescila sie w zalozeniach.';
        } elseif ($hasHrData && in_array(($compliance['hrStatus'] ?? null), ['MINOR_DEVIATION', 'MAJOR_DEVIATION'], true)) {
            $deviations[] = 'Rozklad tetna odbiegal od oczekiwanej strefy.';
        }

        if ($painFlag) {
            $conclusions[] = 'Zgloszony bol ma pierwszenstwo: kolejny plan powinien byc ostrozniejszy.';
        }
        if ($rpe !== null) {
            $conclusions[] = $rpe >= 8
                ? 'RPE bylo wysokie, wiec traktujemy ten dzien jako mocne obciazenie mimo braku telemetryki.'
                : "RPE {$rpe}/10 zapisane jako subiektywny sygnal obciazenia.";
        }
        if ($isManualCheckIn) {
            $conclusions[] = 'Feedback oparty jest na manualnym check-inie, bez danych HR/GPS.';
        }

        if ($painFlag) {
            $planImpact = 'lagodzimy kolejne dni po zgloszeniu bolu';
        } elseif ($rpe !== null && $rpe >= 8) {
            $planImpact = 'kolejny dzien powinien byc spokojniejszy po wysokim RPE';
        } elseif (($signals['warnings']['overloadRisk'] ?? false) === true) {
            $planImpact = 'lagodzimy kolejne dni i pilnujemy ryzyka przeciazenia';
        } elseif ($hasHrData && ($signals['warnings']['hrInstability'] ?? false) === true) {
            $planImpact = 'kolejny akcent powinien byc ostrozniejszy, jesli objawy sie powtorza';
        } elseif ($hasPaceData && ($signals['warnings']['economyDrop'] ?? false) === true) {
            $planImpact = 'dodamy wiecej kontroli tempa / techniki zamiast dokladac obciazenie';
        } else {
            $planImpact = 'bez mocnej korekty planu; kontynuujemy zalozony rytm';
        }

        $confidence = $isManualCheckIn
            ? 'low'
            : (($compliance['durationStatus'] ?? null) !== null || ($compliance['hrStatus'] ?? null) !== null
            ? 'high'
            : ($planCompliance !== 'unknown' ? 'medium' : 'low'));
        $warnings = is_array($signals['warnings'] ?? null) ? $signals['warnings'] : [];
        if ($painFlag) {
            $warnings['painFlag'] = true;
        }
        if ($rpe !== null && $rpe >= 8) {
            $warnings['highRpe'] = true;
        }
        $metrics = is_array($feedback['metrics'] ?? null) ? $feedback['metrics'] : [];
        $metrics['rpe'] = $rpe;
        $metrics['painFlag'] = $painFlag;
        $metrics['dataSource'] = $isManualCheckIn ? 'manual_check_in' : (string) ($workout->source ?? '');
        $conclusions[] = $this->conclusionFromSignals($signals);

        return [
            'feedbackId' => $feedbackId,
            'workoutId' => (int) $workout->id,
            'generatedAtIso' => $createdAt,
            'summary' => [
                'character' => $feedback['character'] ?? 'easy',
                'distanceKm' => $distanceM > 0 ? round($distanceM / 1000.0, 2) : null,
                'movingTimeSec' => $durationSec > 0 ? $durationSec : null,
                'avgPaceSecPerKm' => $hasPaceData ? $pace : null,
                'planCompliance' => $planCompliance,
                'durationStatus' => $compliance['durationStatus'] ?? null,
                'hrStatus' => $compliance['hrStatus'] ?? null,
            ],
            'praise' => array_values(array_unique($praise)),
            'deviations' => array_values(array_unique($deviations)),
            'conclusions' => array_values(array_unique($conclusions)),
            'planImpact' => [
                'label' => $planImpact,
                'warnings' => $warnings,
            ],
            'confidence' => $confidence,
            'metrics' => $metrics,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadCompliance(int $workoutId): array
    {
        $v1 = DB::table('plan_compliance_v1')->where('workout_id', $workoutId)->first();
        $v2 = DB::table('plan_compliance_v2')->where('workout_id', $workoutId)->first();

        return [
            'durationStatus' => $v1?->status,
            'durationRatio' => $v1?->duration_ratio,
            'hrStatus' => $v2?->status,
            'highIntensityRatio' => $v2?->high_intensity_ratio,
            'easyBecameZ5' => $v2 !== null ? (bool) ($v2->flag_easy_became_z5 ?? false) : false,
        ];
    }

    /**
     * @param array<string,mixed> $signals
     */
    private function conclusionFromSignals(array $signals): string
    {
        if (($signals['warnings']['overloadRisk'] ?? false) === true) {
            return 'Wniosek: obciazenie z tej jednostki podbija ryzyko, wiec kolejny trening powinien byc spokojny.';
        }
        if (($signals['warnings']['hrInstability'] ?? false) === true) {
            return 'Wniosek: reakcja tetna sugeruje ostroznosc przed kolejnym akcentem.';
        }
        if (($signals['warnings']['economyDrop'] ?? false) === true) {
            return 'Wniosek: warto popracowac nad rownym tempem i ekonomia, bez dokladania intensywnosci.';
        }

        return 'Wniosek: trening wyglada spojnie z obecnym rytmem, plan moze isc dalej bez duzej korekty.';
    }

    /**
     * @return array{intensityClass:string,hrStable:bool,economyFlag:string,loadImpact:string,warnings:array<string,bool>}|null
     */
    public function getLatestFeedbackSignalsForUser(int $userId): ?array
    {
        $candidates = TrainingFeedbackV2::query()
            ->where('user_id', $userId)
            ->with(['workout:id,summary,created_at'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = null;
        $bestWorkoutDt = -1;
        $bestId = -1;
        foreach ($candidates as $record) {
            $workoutDt = $this->resolveWorkoutDt($record);
            if ($workoutDt > $bestWorkoutDt || ($workoutDt === $bestWorkoutDt && $record->id > $bestId)) {
                $best = $record;
                $bestWorkoutDt = $workoutDt;
                $bestId = $record->id;
            }
        }

        if ($best === null) {
            return null;
        }

        $feedback = $this->parseAndNormalize($best->feedback);
        if ($feedback === null) {
            // fallback: try remaining candidates
            foreach ($candidates as $record) {
                if ($record->id === $best->id) {
                    continue;
                }
                $fb = $this->parseAndNormalize($record->feedback);
                if ($fb !== null) {
                    return FeedbackSignalsMapper::mapFeedbackToSignals($fb);
                }
            }

            return null;
        }

        return FeedbackSignalsMapper::mapFeedbackToSignals($feedback);
    }

    private function resolveWorkoutDt(TrainingFeedbackV2 $record): int
    {
        $workout = $record->workout;
        if (! $workout) {
            return 0;
        }
        $summary = is_array($workout->summary) ? $workout->summary : [];
        $startTimeIso = $summary['startTimeIso'] ?? null;
        if (is_string($startTimeIso) && $startTimeIso !== '') {
            try {
                $ts = Carbon::parse($startTimeIso)->getTimestamp();
                if (is_int($ts) && $ts > 0) {
                    return $ts * 1000;
                }
            } catch (\Throwable) {
                // fallback below
            }
        }

        return $workout->created_at ? $workout->created_at->getTimestamp() * 1000 : 0;
    }

    /**
     * @param  array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float}  $intensity
     */
    private function determineCharacter(array $intensity, float $durationSec): string
    {
        $totalSec = $intensity['z1Sec'] + $intensity['z2Sec'] + $intensity['z3Sec'] + $intensity['z4Sec'] + $intensity['z5Sec'];
        if ($totalSec <= 0) {
            return $durationSec < 600 ? 'regeneracja' : 'easy';
        }

        $z1Pct = ($intensity['z1Sec'] / $totalSec) * 100;
        $z2Pct = ($intensity['z2Sec'] / $totalSec) * 100;
        $z3Pct = ($intensity['z3Sec'] / $totalSec) * 100;
        $z4Pct = ($intensity['z4Sec'] / $totalSec) * 100;
        $z5Pct = ($intensity['z5Sec'] / $totalSec) * 100;

        if ($z5Pct > 20 || ($z4Pct + $z5Pct) > 40) {
            return 'interwał';
        }
        if ($z3Pct > 30 || $z4Pct > 20) {
            return 'tempo';
        }
        if ($durationSec < 600) {
            return 'regeneracja';
        }
        if ($z1Pct > 70 || ($z1Pct + $z2Pct) > 80) {
            return 'easy';
        }

        return 'easy';
    }

    /**
     * Lightweight equivalent of Node parseAndNormalizeFeedbackRecord:
     * parses JSON, fills missing coachSignals/metrics with computed fallbacks,
     * and ensures the minimum shape required by the mapper.
     *
     * @return array<string,mixed>|null
     */
    private function parseAndNormalize(mixed $raw): ?array
    {
        if (is_array($raw)) {
            $data = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (! is_array($data)) {
                return null;
            }
        } else {
            return null;
        }

        // normalize coachSignals
        $coachSignals = $data['coachSignals'] ?? null;
        $needsCompute = ! is_array($coachSignals)
            || array_key_exists('fatigueRisk', $coachSignals)
            || array_key_exists('readiness', $coachSignals)
            || array_key_exists('trainingRole', $coachSignals)
            || ! array_key_exists('hrStable', $coachSignals)
            || ! array_key_exists('economyGood', $coachSignals)
            || ! array_key_exists('loadHeavy', $coachSignals);

        if ($needsCompute) {
            $drift = $data['hrStability']['drift'] ?? null;
            $artefacts = (bool) ($data['hrStability']['artefacts'] ?? false);
            $hrStable = ($drift === null || abs((float) $drift) <= 2) && ! $artefacts;
            $economyGood = ((float) ($data['economy']['paceEquality'] ?? 0)) > 0.8;
            $spikeLoad = (bool) ($data['loadImpact']['spikeLoad'] ?? $data['metrics']['spikeLoad'] ?? false);
            $loadHeavy = ((float) ($data['loadImpact']['weeklyLoadContribution'] ?? 0)) > 50 || $spikeLoad;

            $data['coachSignals'] = [
                'character' => $data['character'] ?? 'easy',
                'hrStable' => $hrStable,
                'economyGood' => $economyGood,
                'loadHeavy' => $loadHeavy,
            ];
        }

        if (! isset($data['metrics']) || ! is_array($data['metrics'])) {
            $data['metrics'] = [
                'hrDrift' => $data['hrStability']['drift'] ?? null,
                'paceEquality' => $data['economy']['paceEquality'] ?? 0,
                'weeklyLoadContribution' => $data['loadImpact']['weeklyLoadContribution'] ?? 0,
                'analysisLoad7d' => $data['loadImpact']['analysisLoad7d'] ?? null,
                'acwr' => $data['loadImpact']['acwr'] ?? null,
                'spikeLoad' => (bool) ($data['loadImpact']['spikeLoad'] ?? false),
            ];
        }

        // drop transient fields
        unset($data['coachConclusion'], $data['generatedAtIso']);

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function analysisFactsForUser(int $userId): array
    {
        try {
            $service = $this->analysisService ?? app(UserTrainingAnalysisService::class);
            $analysis = $service->analyze($userId, 28)->toArray();

            return is_array($analysis['facts'] ?? null) ? $analysis['facts'] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
