<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlanMemoryService;
use App\Services\TrainingAdjustmentsService;
use App\Services\TrainingContextService;
use App\Services\TrainingFeedbackV2Service;
use App\Services\WeeklyPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RollingPlanController extends Controller
{
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

        $nextSession = null;
        foreach ($sessions as $session) {
            if (($session['type'] ?? 'rest') !== 'rest' && (int) ($session['durationMin'] ?? 0) > 0) {
                $nextSession = $session;
                break;
            }
        }

        $response = [
            'generatedAtIso' => $currentPlan['generatedAtIso'],
            'windowDays' => $days,
            'weekStartIso' => $currentPlan['weekStartIso'],
            'horizonEndIso' => CarbonImmutable::parse((string) $currentPlan['weekStartIso'])->addDays($days - 1)->endOfDay()->toISOString(),
            'inputsHash' => hash('sha256', ($currentPlan['inputsHash'] ?? '').'|'.($nextPlan['inputsHash'] ?? '')),
            'sessions' => $sessions,
            'weeks' => $days <= 7 ? [$weeks[0]] : $weeks,
            'summary' => $this->summary($sessions),
            'raceContext' => $context['profile']['primaryRace'] ?? null,
            'blockContext' => $blockContext,
            'appliedAdjustmentsCodes' => array_values(array_map(
                fn ($a) => (string) ($a['code'] ?? ''),
                $adjustments['adjustments'] ?? [],
            )),
            'decisionTrace' => [
                'facts' => [
                    'windowDays' => $context['windowDays'] ?? $days,
                    'totalWorkouts' => $context['signals']['totalWorkouts'] ?? null,
                    'weeklyLoad' => $context['signals']['weeklyLoad'] ?? null,
                    'rolling4wLoad' => $context['signals']['rolling4wLoad'] ?? null,
                    'flags' => $context['signals']['flags'] ?? [],
                ],
                'blockContext' => $blockContext,
                'adjustments' => $adjustments['adjustments'] ?? [],
            ],
            'changedSinceLastPlan' => count($adjustments['adjustments'] ?? []) > 0,
            'nextSession' => $nextSession,
            'rationale' => array_values(array_unique(array_merge(
                is_array($currentPlan['rationale'] ?? null) ? $currentPlan['rationale'] : [],
                is_array($nextPlan['rationale'] ?? null) ? $nextPlan['rationale'] : [],
            ))),
        ];

        return response()
            ->json($response)
            ->header('Cache-Control', 'private, no-cache, must-revalidate');
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
        $total = 0;
        $quality = 0;
        foreach ($sessions as $session) {
            $total += (int) ($session['durationMin'] ?? 0);
            if (in_array((string) ($session['type'] ?? ''), ['quality', 'threshold', 'intervals', 'fartlek', 'tempo'], true)) {
                $quality++;
            }
        }
        return [
            'totalDurationMin' => $total,
            'qualitySessions' => $quality,
            'days' => count($sessions),
        ];
    }

    private function dayOffset(string $day): int
    {
        return ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6][$day] ?? 0;
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
