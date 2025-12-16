export type TrainingDay = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun'

export type SessionType = 'rest' | 'easy' | 'long' | 'quality' | 'strides'

export type PlannedSession = {
  day: TrainingDay
  type: SessionType
  durationMin: number
  distanceKm?: number
  intensityHint?: 'Z1' | 'Z2' | 'Z3' | 'Z4'
  surfaceHint?: 'track' | 'trail' | 'mixed'
  notes?: string[]
}

export type WeeklyPlan = {
  generatedAtIso: string // MUST equal TrainingContext.generatedAtIso
  weekStartIso: string // ISO Monday 00:00Z derived from generatedAtIso week
  weekEndIso: string // ISO Sunday 23:59:59.999Z
  windowDays: number // passthrough from TrainingContext.windowDays
  inputsHash: string // deterministic hash of TrainingContext JSON (sha256 hex)
  sessions: PlannedSession[] // exactly 7 items, ordered mon..sun
  summary: {
    totalDurationMin: number
    totalDistanceKm?: number
    qualitySessions: number
    longRunDay?: TrainingDay
  }
  rationale: string[] // short bullet points, deterministic strings
}

