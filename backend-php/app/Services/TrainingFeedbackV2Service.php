<?php

namespace App\Services;

use App\Models\Workout;
use App\Models\TrainingFeedbackV2;
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
    /**
     * @return array{id:int,feedback:array<string,mixed>,createdAt:string}|null
     */
    public function getFeedbackForWorkout(int $workoutId, int $userId): ?array
    {
        $record = TrainingFeedbackV2::query()
            ->where('workout_id', $workoutId)
            ->where('user_id', $userId)
            ->first();
        if (!$record) {
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
        if (!$workout) {
            return null;
        }

        $summary = is_array($workout->summary) ? $workout->summary : [];
        $durationSec = (float) (
            $summary['trimmed']['durationSec']
            ?? $summary['original']['durationSec']
            ?? $summary['durationSec']
            ?? 0
        );
        $distanceM = (float) (
            $summary['trimmed']['distanceM']
            ?? $summary['original']['distanceM']
            ?? $summary['distanceM']
            ?? 0
        );
        $distanceKm = $distanceM > 0 ? ($distanceM / 1000) : 0.0;
        $paceSecPerKm = $distanceKm > 0 ? ($durationSec / $distanceKm) : 0.0;
        $weeklyLoad = is_numeric($summary['intensity'] ?? null) ? (float) $summary['intensity'] : 0.0;

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
                'weeklyLoadContribution' => $weeklyLoad,
                'intensityScore' => (float) round($intensityScore, 2),
            ],
            'coachSignals' => [
                'character' => $character,
                'hrStable' => $hrStable,
                'economyGood' => $paceEquality > 0.8,
                'loadHeavy' => $weeklyLoad > 50,
            ],
            'metrics' => [
                'hrDrift' => $hrDrift,
                'paceEquality' => $paceEquality,
                'weeklyLoadContribution' => $weeklyLoad,
            ],
            'workoutId' => $workoutId,
        ];

        DB::table('training_feedback_v2')->updateOrInsert(
            ['workout_id' => $workoutId],
            [
                'user_id' => $userId,
                'feedback' => json_encode($feedback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return $feedback;
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
        if (!$workout) {
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
     * @param array{z1Sec:float,z2Sec:float,z3Sec:float,z4Sec:float,z5Sec:float} $intensity
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
            if (!is_array($data)) {
                return null;
            }
        } else {
            return null;
        }

        // normalize coachSignals
        $coachSignals = $data['coachSignals'] ?? null;
        $needsCompute = !is_array($coachSignals)
            || array_key_exists('fatigueRisk', $coachSignals)
            || array_key_exists('readiness', $coachSignals)
            || array_key_exists('trainingRole', $coachSignals)
            || !array_key_exists('hrStable', $coachSignals)
            || !array_key_exists('economyGood', $coachSignals)
            || !array_key_exists('loadHeavy', $coachSignals);

        if ($needsCompute) {
            $drift = $data['hrStability']['drift'] ?? null;
            $artefacts = (bool) ($data['hrStability']['artefacts'] ?? false);
            $hrStable = ($drift === null || abs((float) $drift) <= 2) && !$artefacts;
            $economyGood = ((float) ($data['economy']['paceEquality'] ?? 0)) > 0.8;
            $loadHeavy = ((float) ($data['loadImpact']['weeklyLoadContribution'] ?? 0)) > 50;

            $data['coachSignals'] = [
                'character' => $data['character'] ?? 'easy',
                'hrStable' => $hrStable,
                'economyGood' => $economyGood,
                'loadHeavy' => $loadHeavy,
            ];
        }

        if (!isset($data['metrics']) || !is_array($data['metrics'])) {
            $data['metrics'] = [
                'hrDrift' => $data['hrStability']['drift'] ?? null,
                'paceEquality' => $data['economy']['paceEquality'] ?? 0,
                'weeklyLoadContribution' => $data['loadImpact']['weeklyLoadContribution'] ?? 0,
            ];
        }

        // drop transient fields
        unset($data['coachConclusion'], $data['generatedAtIso']);

        return $data;
    }
}
