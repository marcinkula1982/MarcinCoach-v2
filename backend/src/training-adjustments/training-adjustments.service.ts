import { Injectable } from '@nestjs/common';
import type { TrainingContext } from '../training-context/training-context.types';
import type { TrainingAdjustments } from './training-adjustments.types';
import type { FeedbackSignals } from '../training-feedback-v2/feedback-signals.types';

@Injectable()
export class TrainingAdjustmentsService {
  generate(context: TrainingContext, feedbackSignals?: FeedbackSignals): TrainingAdjustments {
    const adjustments: TrainingAdjustments['adjustments'] = [];

    // 1) fatigue flag
    if (context.signals.flags.fatigue === true) {
      adjustments.push({
        code: 'reduce_load',
        severity: 'high',
        rationale: 'Detected fatigue flag in recent training window',
        evidence: [{ key: 'fatigue', value: true }],
      });
    }

    // 2) no long run
    if (context.signals.longRun.exists === false) {
      adjustments.push({
        code: 'add_long_run',
        severity: 'medium',
        rationale: 'No long run detected in recent training window',
        evidence: [{ key: 'longRun.exists', value: false }],
      });
    }

    // 3) surface constraints
    if (context.profile.surfaces.avoidAsphalt === true) {
      adjustments.push({
        code: 'surface_constraint',
        severity: 'low',
        rationale: 'User prefers to avoid asphalt',
        evidence: [{ key: 'avoidAsphalt', value: true }],
      });
    }

    // 4) FeedbackSignals warnings (BEFORE AI - deterministic adjustments)
    if (feedbackSignals?.warnings?.overloadRisk === true) {
      // Check if reduce_load already exists (don't duplicate)
      const hasReduceLoad = adjustments.some((a) => a.code === 'reduce_load');
      if (!hasReduceLoad) {
        adjustments.push({
          code: 'reduce_load',
          severity: 'high',
          rationale: 'Overload risk detected from latest workout',
          evidence: [{ key: 'overloadRisk', value: true }],
          params: { reductionPct: 25 },
        });
      }
    }

    if (feedbackSignals?.warnings?.hrInstability === true) {
      adjustments.push({
        code: 'recovery_focus',
        severity: 'high',
        rationale: 'HR instability detected after easy workout',
        evidence: [{ key: 'hrInstability', value: true }],
        params: { replaceHardSessionWithEasy: true, longRunReductionPct: 15 },
      });
    }

    if (feedbackSignals?.warnings?.economyDrop === true) {
      adjustments.push({
        code: 'technique_focus',
        severity: 'medium',
        rationale: 'Economy drop detected after easy workout',
        evidence: [{ key: 'economyDrop', value: true }],
        params: { addStrides: true, stridesCount: 6, stridesDurationSec: 20 },
      });
    }

    return {
      generatedAtIso: context.generatedAtIso,
      windowDays: context.windowDays,
      adjustments,
    };
  }
}


