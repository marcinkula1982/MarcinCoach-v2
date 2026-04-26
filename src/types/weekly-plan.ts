export type TrainingDay = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun'

export type SessionType =
  | 'rest'
  | 'easy'
  | 'long'
  | 'quality'
  | 'strides'
  | 'threshold'
  | 'intervals'
  | 'fartlek'
  | 'tempo'

export type PlannedSession = {
  day: TrainingDay
  type: SessionType
  durationMin: number
  distanceKm?: number
  intensityHint?: string
  surfaceHint?: string
  structure?: string
  notes?: string[]
}

export type WeeklyPlan = {
  generatedAtIso: string
  weekStartIso: string
  weekEndIso: string
  windowDays: number
  inputsHash: string
  appliedAdjustmentsCodes?: string[]
  sessions: PlannedSession[]
  summary: {
    totalDurationMin: number
    totalDistanceKm?: number
    qualitySessions: number
    longRunDay?: TrainingDay
  }
  rationale: string[]
}
