import { z } from 'zod'
import { trainingSignalsSchema } from '../training-signals/training-signals.schema'
import type { UserProfileConstraints, TrainingContext } from './training-context.types'

const hrZonesSchema = z
  .object({
    z1: z.tuple([z.number(), z.number()]),
    z2: z.tuple([z.number(), z.number()]),
    z3: z.tuple([z.number(), z.number()]),
    z4: z.tuple([z.number(), z.number()]),
    z5: z.tuple([z.number(), z.number()]),
  })

const userProfileConstraintsSchema = z.object({
  timezone: z.string(),
  runningDays: z.array(z.enum(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])),
  surfaces: z.object({
    preferTrail: z.boolean(),
    avoidAsphalt: z.boolean(),
  }),
  shoes: z.object({
    avoidZeroDrop: z.boolean(),
  }),
  hrZones: hrZonesSchema,
}) satisfies z.ZodType<UserProfileConstraints>

export const trainingContextSchema = z.object({
  generatedAtIso: z.string().datetime(),
  windowDays: z.number(),
  signals: trainingSignalsSchema,
  profile: userProfileConstraintsSchema,
}) satisfies z.ZodType<TrainingContext>

