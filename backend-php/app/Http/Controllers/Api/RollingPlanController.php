<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserProfile;
use App\Services\Analysis\ActivityImpactService;
use App\Services\PlanMemoryService;
use App\Services\TrainingAdjustmentsService;
use App\Services\TrainingContextService;
use App\Services\TrainingFeedbackV2Service;
use App\Services\TrainingSessionBlocksService;
use App\Services\WeeklyPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RollingPlanController extends Controller
{
    private const QUALITY_TYPES = ['quality', 'threshold', 'intervals', 'fartlek', 'tempo'];
    private const DAY_CODES = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(
        private readonly TrainingContextService $contextService,
        private readonly TrainingAdjustmentsService $adjustmentsService,
        private readonly TrainingFeedbackV2Service $feedbackV2Service,
        private readonly WeeklyPlanService $weeklyPlanService,
        private readonly PlanMemoryService $planMemoryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:7', 'max:28'],
        ]);

        $days = (int) ($validated['days'] ?? 14);
        $days = max(7, min(28, $days));
        $userId = $this->authUserId($request);

        return $this->buildRollingPlan($userId, $days, []);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:7', 'max:28'],
            'plannedActivities' => ['nullable', 'array', 'max:28'],
            'plannedActivities.*.dateIso' => ['required', 'date'],
            'plannedActivities.*.sportKind' => ['nullable', 'string', 'max:64'],
            'plannedActivities.*.sport' => ['nullable', 'string', 'max:64'],
            'plannedActivities.*.activityType' => ['nullable', 'string', 'max:64'],
            'plannedActivities.*.sportSubtype' => ['nullable', 'string', 'max:64'],
            'plannedActivities.*.strengthSubtype' => ['nullable', 'string', 'max:64'],
            'plannedActivities.*.durationMin' => ['required', 'integer', 'min:1', 'max:360'],
            'plannedActivities.*.intensity' => ['nullable', 'in:easy,moderate,hard'],
            'plannedActivities.*.elevationGainM' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'crossTrainingPromptPreference' => ['nullable', 'in:ask_before_plan,do_not_ask'],
        ]);

        $days = (int) ($validated['days'] ?? 14);
        $days = max(7, min(28, $days));
        $userId = $this->authUserId($request);

        if (isset($validated['crossTrainingPromptPreference'])) {
            $this->saveCrossTrainingPreference($userId, (string) $validated['crossTrainingPromptPreference']);
        }

        return $this->buildRollingPlan($userId, $days, is_array($validated['plannedActivities'] ?? null) ? $validated['plannedActivities'] : []);
    }

    /**
     * @param list<array<string,mixed>> $rawPlannedActivities
     */
    private function buildRollingPlan(int $userId, int $days, array $rawPlannedActivities): JsonResponse
    {
        $context = $this->contextService->getContextForUser($userId, $days);
        $blockContext = is_array($context['blockContext'] ?? null) ? $context['blockContext'] : null;
        $feedbackSignals = $this->feedbackV2Service->getLatestFeedbackSignalsForUser($userId);
        $adjustments = $this->adjustmentsService->generate($context, $feedbackSignals, $blockContext);

        $currentPlan = $this->weeklyPlanService->generatePlan($context, $adjustments, $blockContext);
        $nextContext = $context;
        $nextContext['generatedAtIso'] = CarbonImmutable::parse((string) $context['generatedAtIso'])->addDays(7)->toISOString();
        $nextBlockContext = $this->shiftBlockContext($blockContext);
        $nextContext['blockContext'] = $nextBlockContext;
        $nextPlan = $this->weeklyPlanService->generatePlan($nextContext, $adjustments, $nextBlockContext);

        $this->rememberWeek($userId, $currentPlan, $blockContext ?? []);
        $this->rememberWeek($userId, $nextPlan, $nextBlockContext ?? []);

        $weeks = [
            $this->publicWeek($currentPlan, 0),
            $this->publicWeek($nextPlan, 1),
        ];
        $sessions = array_values(array_merge($weeks[0]['sessions'], $weeks[1]['sessions']));
        if ($days < 14) {
            $sessions = array_values(array_slice($sessions, 0, $days));
        }

        $horizonStart = CarbonImmutable::parse((string) $currentPlan['weekStartIso'])->startOfDay();
        $plannedCrossTraining = $this->normalizePlannedActivities($rawPlannedActivities, $horizonStart, $days);
        $crossTrainingGuards = $this->applyCrossTrainingGuards($sessions, $plannedCrossTraining, $context);
        $sessions = $this->sortSessions(array_values(array_merge($crossTrainingGuards['sessions'], $plannedCrossTraining)));

        $nextSession = null;
        foreach ($sessions as $session) {
            if (! in_array(($session['type'] ?? 'rest'), ['rest', 'cross_training'], true) && (int) ($session['durationMin'] ?? 0) > 0) {
                $nextSession = $session;
                break;
            }
        }

        $appliedAdjustmentCodes = array_values(array_filter(array_map(
            fn ($a) => (string) ($a['code'] ?? ''),
            $adjustments['adjustments'] ?? [],
        )));
        $appliedAdjustmentCodes = array_values(array_unique(array_merge($appliedAdjustmentCodes, $crossTrainingGuards['appliedCodes'])));

        $response = [
            'generatedAtIso' => $currentPlan['generatedAtIso'],
            'windowDays' => $days,
            'weekStartIso' => $currentPlan['weekStartIso'],
            'horizonEndIso' => CarbonImmutable::parse((string) $currentPlan['weekStartIso'])->addDays($days - 1)->endOfDay()->toISOString(),
            'inputsHash' => hash('sha256', ($currentPlan['inputsHash'] ?? '').'|'.($nextPlan['inputsHash'] ?? '')),
            'sessions' => $sessions,
            'weeks' => $days <= 7 ? [$weeks[0]] : $weeks,
            'summary' => $this->summary($sessions),
            'crossTraining' => [
                'promptPreference' => (string) ($context['profile']['crossTrainingPromptPreference'] ?? 'ask_before_plan'),
                'activities' => $plannedCrossTraining,
                'appliedGuards' => $crossTrainingGuards['guards'],
                'totals' => $this->crossTrainingTotals($plannedCrossTraining),
            ],
            'raceContext' => $context['profile']['primaryRace'] ?? null,
            'blockContext' => $blockContext,
            'appliedAdjustmentsCodes' => $appliedAdjustmentCodes,
            'decisionTrace' => [
                'facts' => [
                    'windowDays' => $context['windowDays'] ?? $days,
                    'totalWorkouts' => $context['signals']['totalWorkouts'] ?? null,
                    'weeklyLoad' => $context['signals']['weeklyLoad'] ?? null,
                    'rolling4wLoad' => $context['signals']['rolling4wLoad'] ?? null,
                    'runningLoad7d' => $context['signals']['runningLoad7d'] ?? null,
                    'crossTrainingFatigue7d' => $context['signals']['crossTrainingFatigue7d'] ?? null,
                    'overallFatigue7d' => $context['signals']['overallFatigue7d'] ?? null,
                    'acwrRunning' => $context['signals']['acwrRunning'] ?? null,
                    'acwrOverall' => $context['signals']['acwrOverall'] ?? null,
                    'flags' => $context['signals']['flags'] ?? [],
                ],
                'blockContext' => $blockContext,
                'adjustments' => $adjustments['adjustments'] ?? [],
                'crossTrainingGuards' => $crossTrainingGuards['guards'],
            ],
            'changedSinceLastPlan' => count($adjustments['adjustments'] ?? []) > 0 || count($crossTrainingGuards['guards']) > 0,
            'nextSession' => $nextSession,
            'rationale' => array_values(array_unique(array_merge(
                is_array($currentPlan['rationale'] ?? null) ? $currentPlan['rationale'] : [],
                is_array($nextPlan['rationale'] ?? null) ? $nextPlan['rationale'] : [],
                count($crossTrainingGuards['guards']) > 0 ? ['Cross-training collision guards applied'] : [],
            ))),
        ];

        return response()
            ->json($response)
            ->header('Cache-Control', 'private, no-cache, must-revalidate');
    }

    private function saveCrossTrainingPreference(int $userId, string $preference): void
    {
        $profile = UserProfile::query()->firstOrCreate(['user_id' => $userId]);
        $constraints = [];
        if (is_string($profile->constraints) && trim($profile->constraints) !== '') {
            $decoded = json_decode($profile->constraints, true);
            $constraints = is_array($decoded) ? $decoded : [];
        }
        $constraints['crossTrainingPromptPreference'] = $preference;
        $profile->constraints = json_encode($constraints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $profile->save();
    }

    /**
     * @param list<array<string,mixed>> $rawActivities
     * @return list<array<string,mixed>>
     */
    private function normalizePlannedActivities(array $rawActivities, CarbonImmutable $horizonStart, int $days): array
    {
        $impactService = new ActivityImpactService();
        $horizonEnd = $horizonStart->addDays($days - 1)->endOfDay();
        $activities = [];

        foreach ($rawActivities as $index => $raw) {
            if (! is_array($raw)) {
                continue;
            }
            try {
                $date = CarbonImmutable::parse((string) ($raw['dateIso'] ?? ''))->startOfDay();
            } catch (\Throwable) {
                continue;
            }
            if ($date->lessThan($horizonStart) || $date->greaterThan($horizonEnd)) {
                continue;
            }

            $sport = $impactService->normalizeSport($raw['sportKind'] ?? $raw['sport'] ?? null, [
                'activityType' => $raw['activityType'] ?? null,
            ]);
            $subtype = $sport === 'strength'
                ? ($impactService->normalizeStrengthSubtype($raw['sportSubtype'] ?? $raw['strengthSubtype'] ?? null) ?? 'full_body')
                : (isset($raw['sportSubtype']) ? strtolower(trim((string) $raw['sportSubtype'])) : null);
            $durationMin = max(1, min(360, (int) ($raw['durationMin'] ?? 0)));
            $intensity = $impactService->normalizeIntensity($raw['intensity'] ?? null);
            $impact = $impactService->impact(
                $sport,
                $subtype,
                $durationMin * 60,
                isset($raw['elevationGainM']) && is_numeric($raw['elevationGainM']) ? (float) $raw['elevationGainM'] : null,
                null,
                ['intensity' => $intensity],
            );
            $offset = (int) $horizonStart->diffInDays($date, false);
            $weekIndex = max(0, intdiv($offset, 7));

            $activities[] = [
                'id' => 'planned-cross-'.$date->format('Ymd').'-'.$index,
                'day' => $this->dayCodeForDate($date),
                'dateIso' => $date->toDateString(),
                'weekIndex' => $weekIndex,
                'type' => 'cross_training',
                'durationMin' => $durationMin,
                'sportKind' => $sport,
                'sportSubtype' => $subtype,
                'intensityHint' => $intensity,
                'activityImpact' => $impact,
                'source' => 'user_planned',
                'notes' => [$this->crossTrainingNote($sport, $subtype, $impact)],
            ];
        }

        return $activities;
    }

    /**
     * @param list<array<string,mixed>> $sessions
     * @param list<array<string,mixed>> $plannedCrossTraining
     * @param array<string,mixed> $context
     * @return array{sessions:list<array<string,mixed>>,guards:list<array<string,mixed>>,appliedCodes:list<string>}
     */
    private function applyCrossTrainingGuards(array $sessions, array $plannedCrossTraining, array $context): array
    {
        $rulesByDate = [];
        $guards = [];
        $appliedCodes = [];
        $highOverallFatigue = (float) ($context['signals']['overallFatigueLoad'] ?? 0.0) >= 120.0
            || (bool) ($context['signals']['flags']['fatigue'] ?? false);

        foreach ($plannedCrossTraining as $activity) {
            $date = (string) ($activity['dateIso'] ?? '');
            $sport = (string) ($activity['sportKind'] ?? 'other');
            $subtype = (string) ($activity['sportSubtype'] ?? '');
            $intensity = (string) ($activity['intensityHint'] ?? 'moderate');
            $duration = (int) ($activity['durationMin'] ?? 0);
            $collision = (string) ($activity['activityImpact']['collisionLevel'] ?? 'none');

            if ($sport === 'strength' && in_array($subtype, ['lower_body', 'full_body'], true) && ($intensity === 'hard' || $collision === 'high')) {
                $this->addGuardRule($rulesByDate, $date, 'quality', 'hard_lower_strength_same_day');
                $this->addGuardRule($rulesByDate, $this->addDays($date, 1), 'quality', 'hard_lower_strength_next_day');
                continue;
            }

            if ($sport === 'bike' && ($intensity === 'hard' || $duration > 60)) {
                $this->addGuardRule($rulesByDate, $date, 'quality', 'hard_or_long_bike_same_day');
                if ($highOverallFatigue) {
                    $this->addGuardRule($rulesByDate, $this->addDays($date, 1), 'quality', 'bike_after_high_fatigue');
                }
                continue;
            }

            if ($sport === 'swim' && $intensity === 'hard') {
                $this->addGuardRule($rulesByDate, $date, 'quality', 'hard_swim_same_day');
                continue;
            }

            if ($sport === 'walk_hike' && $duration > 120) {
                $this->addGuardRule($rulesByDate, $this->addDays($date, 1), 'quality_long', 'long_walk_hike_next_day');
                continue;
            }

            if ($sport === 'other') {
                $this->addGuardRule($rulesByDate, $date, 'quality', 'other_activity_needs_classification');
            }
        }

        foreach ($sessions as &$session) {
            $date = (string) ($session['dateIso'] ?? '');
            if ($date === '' || ! isset($rulesByDate[$date])) {
                continue;
            }

            $type = (string) ($session['type'] ?? 'rest');
            foreach ($rulesByDate[$date] as $rule) {
                if (in_array($type, self::QUALITY_TYPES, true) && in_array($rule['scope'], ['quality', 'quality_long'], true)) {
                    $originalType = $type;
                    $originalDuration = (int) ($session['durationMin'] ?? 0);
                    $session['type'] = 'easy';
                    $session['durationMin'] = min($originalDuration, 40);
                    $session['intensityHint'] = 'Z2';
                    unset($session['structure']);
                    $session['blocks'] = (new TrainingSessionBlocksService())->blocksForSession($session);
                    $session['notes'] = array_values(array_merge(
                        is_array($session['notes'] ?? null) ? $session['notes'] : [],
                        ["Cross-training guard: {$rule['reason']} changed {$originalType} to easy."]
                    ));
                    $guards[] = [
                        'dateIso' => $date,
                        'scope' => $rule['scope'],
                        'reason' => $rule['reason'],
                        'action' => 'quality_to_easy',
                    ];
                    $appliedCodes[] = 'cross_training_collision_guard';
                    break;
                }

                if ($type === 'long' && $rule['scope'] === 'quality_long') {
                    $originalDuration = (int) ($session['durationMin'] ?? 0);
                    $session['durationMin'] = $this->roundToFive(max(30.0, $originalDuration * 0.85));
                    $session['blocks'] = (new TrainingSessionBlocksService())->blocksForSession($session);
                    $session['notes'] = array_values(array_merge(
                        is_array($session['notes'] ?? null) ? $session['notes'] : [],
                        ["Cross-training guard: {$rule['reason']} shortened long run."]
                    ));
                    $guards[] = [
                        'dateIso' => $date,
                        'scope' => $rule['scope'],
                        'reason' => $rule['reason'],
                        'action' => 'long_run_shortened',
                    ];
                    $appliedCodes[] = 'cross_training_collision_guard';
                    break;
                }
            }
        }
        unset($session);

        return [
            'sessions' => array_values($sessions),
            'guards' => $guards,
            'appliedCodes' => array_values(array_unique($appliedCodes)),
        ];
    }

    /**
     * @param array<string,list<array{scope:string,reason:string}>> $rulesByDate
     */
    private function addGuardRule(array &$rulesByDate, string $dateIso, string $scope, string $reason): void
    {
        if ($dateIso === '') {
            return;
        }
        $rulesByDate[$dateIso] ??= [];
        $rulesByDate[$dateIso][] = ['scope' => $scope, 'reason' => $reason];
    }

    private function addDays(string $dateIso, int $days): string
    {
        try {
            return CarbonImmutable::parse($dateIso)->addDays($days)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param list<array<string,mixed>> $sessions
     * @return list<array<string,mixed>>
     */
    private function sortSessions(array $sessions): array
    {
        usort($sessions, function (array $a, array $b): int {
            $dateCmp = strcmp((string) ($a['dateIso'] ?? ''), (string) ($b['dateIso'] ?? ''));
            if ($dateCmp !== 0) {
                return $dateCmp;
            }
            $aPriority = (($a['type'] ?? '') === 'cross_training') ? 1 : 0;
            $bPriority = (($b['type'] ?? '') === 'cross_training') ? 1 : 0;

            return $aPriority <=> $bPriority;
        });

        return array_values($sessions);
    }

    private function dayCodeForDate(CarbonImmutable $date): string
    {
        return self::DAY_CODES[max(0, min(6, $date->dayOfWeekIso - 1))];
    }

    /**
     * @param array<string,mixed> $impact
     */
    private function crossTrainingNote(string $sport, ?string $subtype, array $impact): string
    {
        $parts = [$sport];
        if ($subtype !== null && $subtype !== '') {
            $parts[] = $subtype;
        }
        $parts[] = (string) ($impact['intensity'] ?? 'moderate');
        $parts[] = 'collision: '.(string) ($impact['collisionLevel'] ?? 'none');

        return implode(' / ', $parts);
    }

    /**
     * @param array<string,mixed>|null $blockContext
     * @return array<string,mixed>|null
     */
    private function shiftBlockContext(?array $blockContext): ?array
    {
        if ($blockContext === null) {
            return null;
        }
        $next = $blockContext;
        if (isset($next['weeks_until_race']) && is_numeric($next['weeks_until_race'])) {
            $weeks = max(0, (int) $next['weeks_until_race'] - 1);
            $next['weeks_until_race'] = $weeks;
            if ($weeks <= 2) {
                $next['block_type'] = 'taper';
                $next['week_role'] = 'taper';
                $next['load_direction'] = 'decrease';
                $next['key_capability_focus'] = 'economy';
                $next['block_goal'] = 'Taper przed startem docelowym';
            } elseif ($weeks <= 6) {
                $next['block_type'] = 'peak';
                $next['week_role'] = 'peak';
                $next['key_capability_focus'] = 'vo2max';
                $next['block_goal'] = 'Szczyt formy przed startem docelowym';
            }
        }
        return $next;
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function publicWeek(array $plan, int $weekIndex): array
    {
        $weekStart = CarbonImmutable::parse((string) $plan['weekStartIso'])->startOfDay();
        $sessions = array_values(array_map(function (array $session) use ($weekStart, $weekIndex): array {
            unset($session['techniqueFocus'], $session['surfaceHint']);
            $offset = $this->dayOffset((string) ($session['day'] ?? 'mon'));
            $session['dateIso'] = $weekStart->addDays($offset)->toDateString();
            $session['weekIndex'] = $weekIndex;
            return $session;
        }, is_array($plan['sessions'] ?? null) ? $plan['sessions'] : []));

        return [
            'weekIndex' => $weekIndex,
            'weekStartIso' => $plan['weekStartIso'],
            'weekEndIso' => $plan['weekEndIso'],
            'blockContext' => $plan['blockContext'] ?? null,
            'summary' => $plan['summary'] ?? null,
            'sessions' => $sessions,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $sessions
     * @return array<string,mixed>
     */
    private function summary(array $sessions): array
    {
        $runningTotal = 0;
        $crossTrainingDuration = 0;
        $crossTrainingFatigue = 0.0;
        $quality = 0;
        $days = [];
        foreach ($sessions as $session) {
            $type = (string) ($session['type'] ?? '');
            if (isset($session['dateIso'])) {
                $days[(string) $session['dateIso']] = true;
            }
            if ($type === 'cross_training') {
                $crossTrainingDuration += (int) ($session['durationMin'] ?? 0);
                if (isset($session['activityImpact']['crossTrainingFatigueMin']) && is_numeric($session['activityImpact']['crossTrainingFatigueMin'])) {
                    $crossTrainingFatigue += (float) $session['activityImpact']['crossTrainingFatigueMin'];
                }
                continue;
            }

            if ($type !== 'rest') {
                $runningTotal += (int) ($session['durationMin'] ?? 0);
            }
            if (in_array($type, self::QUALITY_TYPES, true)) {
                $quality++;
            }
        }
        return [
            'totalDurationMin' => $runningTotal,
            'crossTrainingDurationMin' => $crossTrainingDuration,
            'overallFatigueLoadMin' => round($runningTotal + $crossTrainingFatigue, 2),
            'qualitySessions' => $quality,
            'days' => count($days) > 0 ? count($days) : count($sessions),
        ];
    }

    /**
     * @param list<array<string,mixed>> $plannedCrossTraining
     * @return array{plannedDurationMin:int,crossTrainingFatigueMin:float,overallFatigueLoadMin:float}
     */
    private function crossTrainingTotals(array $plannedCrossTraining): array
    {
        $duration = 0;
        $fatigue = 0.0;
        foreach ($plannedCrossTraining as $activity) {
            $duration += (int) ($activity['durationMin'] ?? 0);
            if (isset($activity['activityImpact']['crossTrainingFatigueMin']) && is_numeric($activity['activityImpact']['crossTrainingFatigueMin'])) {
                $fatigue += (float) $activity['activityImpact']['crossTrainingFatigueMin'];
            }
        }

        return [
            'plannedDurationMin' => $duration,
            'crossTrainingFatigueMin' => round($fatigue, 2),
            'overallFatigueLoadMin' => round($fatigue, 2),
        ];
    }

    private function dayOffset(string $day): int
    {
        return ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6][$day] ?? 0;
    }

    private function roundToFive(float $minutes): int
    {
        return (int) (round($minutes / 5) * 5);
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $blockContext
     */
    private function rememberWeek(int $userId, array $plan, array $blockContext): void
    {
        try {
            $this->planMemoryService->upsertWeekFromPlan($userId, $plan, $blockContext);
        } catch (\Throwable) {
        }
    }
}
