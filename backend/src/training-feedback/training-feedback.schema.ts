import { z } from 'zod'
import type { PlanCompliance, PlanFeedbackSignals } from './training-feedback.types'

const planComplianceSchema = z.enum(['planned', 'modified', 'unplanned', 'unknown'])

const noteItemSchema = z.object({
  workoutId: z.number(),
  workoutDtIso: z.string().datetime(),
  note: z.string(),
})

export const planFeedbackSignalsSchema = z.object({
  generatedAtIso: z.string().datetime(),
  windowDays: z.number(),
  counts: z.object({
    totalSessions: z.number(),
    planned: z.number(),
    modified: z.number(),
    unplanned: z.number(),
    unknown: z.number(),
  }),
  complianceRate: z.object({
    plannedPct: z.number().min(0).max(100).multipleOf(0.01), // 0..100, 2 decimals
    modifiedPct: z.number().min(0).max(100).multipleOf(0.01),
    unplannedPct: z.number().min(0).max(100).multipleOf(0.01),
  }),
  rpe: z.object({
    samples: z.number(),
    avg: z.number().multipleOf(0.1).optional(), // 1 decimal
    p50: z.number().multipleOf(0.1).optional(), // median, 1 decimal
  }),
  fatigue: z.object({
    trueCount: z.number(),
    falseCount: z.number(),
  }),
  notes: z.object({
    samples: z.number(),
    last5: z.array(noteItemSchema).max(5), // max 5 items
  }),
}) as z.ZodType<PlanFeedbackSignals>


