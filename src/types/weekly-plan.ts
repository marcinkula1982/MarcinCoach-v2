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
  | 'cross_training'

export type CrossTrainingPromptPreference = 'ask_before_plan' | 'do_not_ask'

export type CrossTrainingSport =
  | 'bike'
  | 'swim'
  | 'walk_hike'
  | 'strength'
  | 'other'

export type CrossTrainingIntensity = 'easy' | 'moderate' | 'hard'

export type ActivityImpact = {
  sportKind: string
  sportSubtype?: string | null
  intensity: CrossTrainingIntensity
  durationMin: number
  runningLoadMin: number
  crossTrainingFatigueMin: number
  overallFatigueMin: number
  collisionLevel: 'none' | 'low' | 'medium' | 'high'
  affectedSystems: string[]
  needsUserClassification: boolean
}

export type PlannedCrossTrainingActivity = {
  dateIso: string
  sportKind: CrossTrainingSport
  sportSubtype?: string | null
  durationMin: number
  intensity?: CrossTrainingIntensity
  elevationGainM?: number
}

export type CrossTrainingPlanInfo = {
  promptPreference: CrossTrainingPromptPreference
  activities: PlannedSession[]
  appliedGuards: Array<Record<string, unknown>>
  totals: {
    plannedDurationMin: number
    crossTrainingFatigueMin: number
    overallFatigueLoadMin: number
  }
}

export type WorkoutBlock = {
  kind: 'warmup' | 'main' | 'cooldown' | string
  title: string
  durationMin: number
  intensityHint?: string
  description?: string
  tips?: string[]
}

export type PlannedSession = {
  day: TrainingDay
  type: SessionType
  durationMin: number
  id?: string
  dateIso?: string
  weekIndex?: number
  distanceKm?: number
  intensityHint?: string
  surfaceHint?: string
  structure?: string
  notes?: string[]
  blocks?: WorkoutBlock[]
  sportKind?: string
  sportSubtype?: string | null
  source?: string
  activityImpact?: ActivityImpact
}

export type WeeklyPlan = {
  generatedAtIso: string
  weekStartIso: string
  weekEndIso?: string
  horizonEndIso?: string
  windowDays: number
  inputsHash: string
  appliedAdjustmentsCodes?: string[]
  sessions: PlannedSession[]
  weeks?: Array<Record<string, unknown>>
  crossTraining?: CrossTrainingPlanInfo
  nextSession?: PlannedSession | null
  decisionTrace?: Record<string, unknown>
  summary: {
    totalDurationMin: number
    crossTrainingDurationMin?: number
    overallFatigueLoadMin?: number
    totalDistanceKm?: number
    qualitySessions: number
    longRunDay?: TrainingDay
    days?: number
  }
  rationale: string[]
}
