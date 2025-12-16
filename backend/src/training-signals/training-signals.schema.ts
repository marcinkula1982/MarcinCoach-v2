import { z } from 'zod'
import type { TrainingSignals } from './training-signals.types'

const intensitySchema = z.object({
  z1Sec: z.number(),
  z2Sec: z.number(),
  z3Sec: z.number(),
  z4Sec: z.number(),
  z5Sec: z.number(),
  totalSec: z.number(),
})

export const trainingSignalsSchema = z.object({
  period: z.object({
    from: z.string(),
    to: z.string(),
  }),
  volume: z.object({
    distanceKm: z.number(),
    durationMin: z.number(),
    sessions: z.number(),
  }),
  intensity: intensitySchema,
  longRun: z.object({
    exists: z.boolean(),
    distanceKm: z.number(),
    durationMin: z.number(),
    workoutId: z.number().nullable(),
    workoutDt: z.string().nullable(),
  }),
  load: z.object({
    weeklyLoad: z.number(),
    rolling4wLoad: z.number(),
  }),
  consistency: z.object({
    sessionsPerWeek: z.number(),
    streakWeeks: z.number(),
  }),
  flags: z.object({
    injuryRisk: z.boolean(),
    fatigue: z.boolean(),
  }),
})

export type TrainingSignalsValidated = TrainingSignals

