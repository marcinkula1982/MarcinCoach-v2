import { z } from 'zod'

export const characterSchema = z.enum(['easy', 'tempo', 'interwał', 'regeneracja'])

export const hrStabilitySchema = z.object({
  drift: z.number().nullable(),
  artefacts: z.boolean(),
})

export const economySchema = z.object({
  paceEquality: z.number(),
  variance: z.number(),
})

export const loadImpactSchema = z.object({
  weeklyLoadContribution: z.number(),
  intensityScore: z.number(),
})

export const coachSignalsSchema = z.object({
  character: characterSchema,
  hrStable: z.boolean(),
  economyGood: z.boolean(),
  loadHeavy: z.boolean(),
})

export const metricsSchema = z.object({
  hrDrift: z.number().nullable(),
  paceEquality: z.number(),
  weeklyLoadContribution: z.number(),
})

export const trainingFeedbackV2Schema = z.object({
  character: characterSchema,
  hrStability: hrStabilitySchema,
  economy: economySchema,
  loadImpact: loadImpactSchema,
  coachSignals: coachSignalsSchema,
  metrics: metricsSchema,
  workoutId: z.number(),
})

export const trainingFeedbackV2ResponseSchema = trainingFeedbackV2Schema.extend({
  generatedAtIso: z.string(),
  coachConclusion: z.string(),
})

