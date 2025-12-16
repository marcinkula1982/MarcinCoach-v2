export type PlanCompliance = 'planned' | 'modified' | 'unplanned' | 'unknown'

export type PlanFeedbackSignals = {
  generatedAtIso: string // MUST equal signals.period.to (z TrainingSignals)
  windowDays: number
  counts: {
    totalSessions: number
    planned: number
    modified: number
    unplanned: number
    unknown: number
  }
  complianceRate: {
    plannedPct: number // 0..100, 2 decimals
    modifiedPct: number
    unplannedPct: number
  }
  rpe: {
    samples: number
    avg?: number // 1 decimal
    p50?: number // median, 1 decimal
  }
  fatigue: {
    trueCount: number
    falseCount: number
  }
  notes: {
    samples: number
    last5: Array<{ workoutId: number; workoutDtIso: string; note: string }>
  }
}


