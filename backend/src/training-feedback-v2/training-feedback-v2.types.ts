export type Character = 'easy' | 'tempo' | 'interwał' | 'regeneracja'

export type HrStability = {
  drift: number | null // bpm/min - trend liniowy HR w czasie
  artefacts: boolean // skoki >30 bpm między sąsiednimi punktami
}

export type Economy = {
  paceEquality: number // współczynnik równości tempa (1 - CV/mean)
  variance: number // wariancja tempa
}

export type LoadImpact = {
  weeklyLoadContribution: number // używa summary.intensity (liczba) - kompatybilne z TrainingSignalsService
  intensityScore: number // ważona suma intensity buckets (opcjonalnie, dla informacji)
}

export type CoachSignals = {
  character: Character
  hrStable: boolean
  economyGood: boolean
  loadHeavy: boolean
}

export type Metrics = {
  hrDrift: number | null
  paceEquality: number
  weeklyLoadContribution: number
}

export type TrainingFeedbackV2 = {
  character: Character
  hrStability: HrStability
  economy: Economy
  loadImpact: LoadImpact
  coachSignals: CoachSignals
  metrics: Metrics
  workoutId: number
}

export type TrainingFeedbackV2Response = TrainingFeedbackV2 & {
  generatedAtIso: string
  coachConclusion: string
}

