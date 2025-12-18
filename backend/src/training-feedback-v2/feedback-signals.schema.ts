import { z } from 'zod'
import type { FeedbackSignals } from './feedback-signals.types'

export const feedbackSignalsSchema = z.object({
  intensityClass: z.enum(['easy', 'moderate', 'hard']),
  hrStable: z.boolean(),
  economyFlag: z.enum(['good', 'ok', 'poor']),
  loadImpact: z.enum(['low', 'medium', 'high']),
  warnings: z.object({
    economyDrop: z.boolean().optional(),
    hrInstability: z.boolean().optional(),
    overloadRisk: z.boolean().optional(),
  }),
})

export type FeedbackSignalsValidated = FeedbackSignals

