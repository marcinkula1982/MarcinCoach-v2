import { z } from 'zod'
import type { TrainingDay, SessionType, PlannedSession, WeeklyPlan } from './weekly-plan.types'

const trainingDaySchema = z.enum(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])

const sessionTypeSchema = z.enum(['rest', 'easy', 'long', 'quality', 'strides'])

const plannedSessionSchema = z.object({
  day: trainingDaySchema,
  type: sessionTypeSchema,
  durationMin: z.number(),
  distanceKm: z.number().optional(),
  intensityHint: z.enum(['Z1', 'Z2', 'Z3', 'Z4']).optional(),
  surfaceHint: z.enum(['track', 'trail', 'mixed']).optional(),
  notes: z.array(z.string()).optional(),
})

export const weeklyPlanSchema = z
  .object({
    generatedAtIso: z.string().datetime(),
    weekStartIso: z.string().datetime(),
    weekEndIso: z.string().datetime(),
    windowDays: z.number(),
    inputsHash: z.string().length(64).regex(/^[0-9a-f]{64}$/), // 64-char hex
    sessions: z
      .array(plannedSessionSchema)
      .length(7)
      .refine(
        (sessions) => {
          const days = sessions.map((s) => s.day)
          return new Set(days).size === 7 && days.length === 7
        },
        { message: 'Sessions must have unique days (mon..sun)' },
      )
      .refine(
        (sessions) => {
          const expectedOrder: TrainingDay[] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
          return sessions.every((s, i) => s.day === expectedOrder[i])
        },
        { message: 'Sessions must be ordered mon..sun' },
      ),
    summary: z.object({
      totalDurationMin: z.number(),
      totalDistanceKm: z.number().optional(),
      qualitySessions: z.number(),
      longRunDay: trainingDaySchema.optional(),
    }),
    rationale: z.array(z.string()),
  })
  .refine(
    (plan) => {
      // Validate weekEndIso is Sunday 23:59:59.999Z
      const endDate = new Date(plan.weekEndIso)
      return endDate.getUTCDay() === 0 // Sunday
    },
    { message: 'weekEndIso must be Sunday' },
  )
  .refine(
    (plan) => {
      // Validate weekStartIso is Monday 00:00:00.000Z
      const startDate = new Date(plan.weekStartIso)
      return startDate.getUTCDay() === 1 && startDate.getUTCHours() === 0 && startDate.getUTCMinutes() === 0 && startDate.getUTCSeconds() === 0 && startDate.getUTCMilliseconds() === 0
    },
    { message: 'weekStartIso must be Monday 00:00:00.000Z' },
  )
  .refine(
    (plan) => {
      // Validate summary matches sessions
      const actualQualitySessions = plan.sessions.filter((s) => s.type === 'quality').length
      const actualLongRunDay = plan.sessions.find((s) => s.type === 'long')?.day
      const actualTotalDuration = plan.sessions.reduce((sum, s) => sum + s.durationMin, 0)
      const actualTotalDistance = plan.sessions.reduce((sum, s) => sum + (s.distanceKm ?? 0), 0)

      return (
        actualQualitySessions === plan.summary.qualitySessions &&
        actualLongRunDay === plan.summary.longRunDay &&
        Math.abs(actualTotalDuration - plan.summary.totalDurationMin) < 0.01 &&
        (plan.summary.totalDistanceKm === undefined ||
          Math.abs(actualTotalDistance - plan.summary.totalDistanceKm) < 0.01)
      )
    },
    { message: 'Summary must match actual session data' },
  ) as z.ZodType<WeeklyPlan>

