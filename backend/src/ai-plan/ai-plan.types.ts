import type { TrainingAdjustments } from '../training-adjustments/training-adjustments.types'
import type { WeeklyPlan } from '../weekly-plan/weekly-plan.types'

export type AiPlanExplanation = {
  titlePl: string
  summaryPl: string[]
  sessionNotesPl: Array<{ day: string; text: string }>
  warningsPl: string[]
  confidence: number // 0.2..0.9
}

export type AiPlanResponse = {
  provider: 'stub' | 'openai'
  generatedAtIso: string // = context.generatedAtIso
  windowDays: number
  plan: WeeklyPlan & { appliedAdjustmentsCodes?: string[] }
  adjustments: TrainingAdjustments
  explanation: AiPlanExplanation
}


