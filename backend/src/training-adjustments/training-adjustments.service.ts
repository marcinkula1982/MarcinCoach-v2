import { Injectable } from '@nestjs/common';
import type { TrainingContext } from '../training-context/training-context.types';
import type { TrainingAdjustments } from './training-adjustments.types';

@Injectable()
export class TrainingAdjustmentsService {
  generate(context: TrainingContext): TrainingAdjustments {
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

    return {
      generatedAtIso: context.generatedAtIso,
      windowDays: context.windowDays,
      adjustments,
    };
  }
}


