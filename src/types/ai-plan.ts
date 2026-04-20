import type { WeeklyPlan } from './weekly-plan'
import type { TrainingAdjustments } from './training-adjustments'

export type AiPlanResponse = {
  provider: 'stub' | 'openai' | 'cache'
  generatedAtIso: string
  windowDays: number
  plan: WeeklyPlan
  adjustments: TrainingAdjustments
  explanation: {
    titlePl: string
    summaryPl: string[]
    sessionNotesPl: Array<{ day: string; text: string }>
    warningsPl: string[]
    confidence: number
  }
}
