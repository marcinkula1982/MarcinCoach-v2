import { AiPlanService } from '../src/ai-plan/ai-plan.service'
import { AiCacheService } from '../src/ai-cache/ai-cache.service'
import { TrainingFeedbackV2Service } from '../src/training-feedback-v2/training-feedback-v2.service'
import type { TrainingContext } from '../src/training-context/training-context.types'
import type { TrainingAdjustments } from '../src/training-adjustments/training-adjustments.types'
import type { WeeklyPlan } from '../src/weekly-plan/weekly-plan.types'

describe('AiPlanService (stub)', () => {
  const mockAiCacheService = {
    get: jest.fn().mockReturnValue(null),
    set: jest.fn(),
  } as any

  const mockTrainingFeedbackV2Service = {
    getLatestFeedbackSignalsForUser: jest.fn().mockResolvedValue(undefined),
  } as any

  const mockPlanSnapshotService = {
    saveForUser: jest.fn(),
    getForWorkoutDate: jest.fn(),
  }
  const service = new AiPlanService(mockAiCacheService, mockTrainingFeedbackV2Service, mockPlanSnapshotService as any)

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

    const out1 = await service.buildResponse(1, ctx, adjustments, plan)
    const out2 = await service.buildResponse(1, ctx, adjustments, plan)
    expect(out1).toEqual(out2)
  })

  it('applies reduce_load adjustment when overloadRisk is true', async () => {
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
      adjustments: [
        {
          code: 'reduce_load',
          severity: 'high',
          rationale: 'Overload risk',
          evidence: [],
          params: { reductionPct: 25 },
        },
      ],
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
        { day: 'wed', type: 'easy', durationMin: 40, intensityHint: 'Z2' },
        { day: 'thu', type: 'rest', durationMin: 0 },
        { day: 'fri', type: 'easy', durationMin: 40, intensityHint: 'Z2' },
        { day: 'sat', type: 'rest', durationMin: 0 },
        { day: 'sun', type: 'long', durationMin: 90, intensityHint: 'Z2' },
      ],
      summary: { totalDurationMin: 210, qualitySessions: 0 },
      rationale: [],
      appliedAdjustmentsCodes: ['reduce_load'],
    }

    // Mock TrainingFeedbackV2Service
    ;(mockTrainingFeedbackV2Service.getLatestFeedbackSignalsForUser as jest.Mock).mockResolvedValue({
      warnings: { overloadRisk: true },
    })

    const out = await service.buildResponse(1, ctx, adjustments, plan)

    // Sprawdź że appliedAdjustmentsCodes zawiera reduce_load
    expect(out.plan.appliedAdjustmentsCodes).toContain('reduce_load')
    // Sprawdź że plan nie ma sesji typu 'quality'
    expect(out.plan.sessions.every((s) => s.type !== 'quality')).toBe(true)
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

    const out = await service.buildResponse(1, ctx, adjustments, plan)

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


