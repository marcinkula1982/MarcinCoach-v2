import { AiPlanService } from '../src/ai-plan/ai-plan.service'
import type { TrainingContext } from '../src/training-context/training-context.types'
import type { TrainingAdjustments } from '../src/training-adjustments/training-adjustments.types'
import type { WeeklyPlan } from '../src/weekly-plan/weekly-plan.types'

describe('AiPlanService (stub)', () => {
  const service = new AiPlanService()

  beforeEach(() => {
    process.env.AI_PLAN_PROVIDER = 'stub'
  })

  it('is deterministic: same input => same output', async () => {
    const ctx: TrainingContext = {
      generatedAtIso: '2024-01-15T12:00:00.000Z',
      windowDays: 28,
      signals: {
        period: { from: '2024-01-01T00:00:00.000Z', to: '2024-01-15T12:00:00.000Z' },
        volume: { distanceKm: 100, durationMin: 400, sessions: 4 },
        intensity: { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0, totalSec: 0 },
        longRun: { exists: true, distanceKm: 15, durationMin: 90, workoutId: 1, workoutDt: '2024-01-14T10:00:00.000Z' },
        load: { weeklyLoad: 200, rolling4wLoad: 800 },
        consistency: { sessionsPerWeek: 4, streakWeeks: 2 },
        flags: { injuryRisk: false, fatigue: false },
      },
      profile: {
        timezone: 'Europe/Warsaw',
        runningDays: ['mon', 'wed', 'fri', 'sun'],
        surfaces: { preferTrail: false, avoidAsphalt: false },
        shoes: { avoidZeroDrop: false },
        hrZones: {
          z1: [0, 0],
          z2: [0, 0],
          z3: [0, 0],
          z4: [0, 0],
          z5: [0, 0],
        },
      },
    }

    const adjustments: TrainingAdjustments = {
      generatedAtIso: ctx.generatedAtIso,
      windowDays: ctx.windowDays,
      adjustments: [],
    }

    const plan: WeeklyPlan & { appliedAdjustmentsCodes?: string[] } = {
      generatedAtIso: ctx.generatedAtIso,
      weekStartIso: '2024-01-15T00:00:00.000Z',
      weekEndIso: '2024-01-21T23:59:59.999Z',
      windowDays: ctx.windowDays,
      inputsHash: '0'.repeat(64),
      sessions: [
        { day: 'mon', type: 'easy', durationMin: 40, intensityHint: 'Z2' },
        { day: 'tue', type: 'rest', durationMin: 0 },
        { day: 'wed', type: 'quality', durationMin: 50, intensityHint: 'Z3' },
        { day: 'thu', type: 'rest', durationMin: 0 },
        { day: 'fri', type: 'easy', durationMin: 40, intensityHint: 'Z2' },
        { day: 'sat', type: 'rest', durationMin: 0 },
        { day: 'sun', type: 'long', durationMin: 90, intensityHint: 'Z2' },
      ],
      summary: { totalDurationMin: 220, qualitySessions: 1, longRunDay: 'sun' },
      rationale: ['Weekly plan based on last 28 days window'],
      appliedAdjustmentsCodes: [],
    }

    const out1 = await service.buildResponse(ctx, adjustments, plan)
    const out2 = await service.buildResponse(ctx, adjustments, plan)
    expect(out1).toEqual(out2)
  })

  it('returns required fields in response (shape check)', async () => {
    const ctx: TrainingContext = {
      generatedAtIso: '2024-01-15T12:00:00.000Z',
      windowDays: 28,
      signals: {
        period: { from: '2024-01-01T00:00:00.000Z', to: '2024-01-15T12:00:00.000Z' },
        volume: { distanceKm: 0, durationMin: 0, sessions: 0 },
        intensity: { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0, totalSec: 0 },
        longRun: { exists: false, distanceKm: 0, durationMin: 0, workoutId: null, workoutDt: null },
        load: { weeklyLoad: 0, rolling4wLoad: 0 },
        consistency: { sessionsPerWeek: 0, streakWeeks: 0 },
        flags: { injuryRisk: false, fatigue: false },
      },
      profile: {
        timezone: 'Europe/Warsaw',
        runningDays: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
        surfaces: { preferTrail: false, avoidAsphalt: false },
        shoes: { avoidZeroDrop: false },
        hrZones: {
          z1: [0, 0],
          z2: [0, 0],
          z3: [0, 0],
          z4: [0, 0],
          z5: [0, 0],
        },
      },
    }

    const adjustments: TrainingAdjustments = {
      generatedAtIso: ctx.generatedAtIso,
      windowDays: ctx.windowDays,
      adjustments: [],
    }

    const plan: WeeklyPlan & { appliedAdjustmentsCodes?: string[] } = {
      generatedAtIso: ctx.generatedAtIso,
      weekStartIso: '2024-01-15T00:00:00.000Z',
      weekEndIso: '2024-01-21T23:59:59.999Z',
      windowDays: ctx.windowDays,
      inputsHash: '0'.repeat(64),
      sessions: [
        { day: 'mon', type: 'rest', durationMin: 0 },
        { day: 'tue', type: 'rest', durationMin: 0 },
        { day: 'wed', type: 'rest', durationMin: 0 },
        { day: 'thu', type: 'rest', durationMin: 0 },
        { day: 'fri', type: 'rest', durationMin: 0 },
        { day: 'sat', type: 'rest', durationMin: 0 },
        { day: 'sun', type: 'rest', durationMin: 0 },
      ],
      summary: { totalDurationMin: 0, qualitySessions: 0 },
      rationale: [],
    }

    const out = await service.buildResponse(ctx, adjustments, plan)

    expect(out.generatedAtIso).toBe(ctx.generatedAtIso)
    expect(out.windowDays).toBe(ctx.windowDays)
    expect(out.provider).toBe('stub')
    expect(out.plan).toBeDefined()
    expect(out.adjustments).toBeDefined()
    expect(out.explanation).toBeDefined()
    expect(typeof out.explanation.titlePl).toBe('string')
    expect(Array.isArray(out.explanation.summaryPl)).toBe(true)
    expect(Array.isArray(out.explanation.sessionNotesPl)).toBe(true)
    expect(Array.isArray(out.explanation.warningsPl)).toBe(true)
    expect(typeof out.explanation.confidence).toBe('number')
  })
})


