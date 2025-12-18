import type { TrainingContext } from '../src/training-context/training-context.types'
import type { TrainingAdjustments } from '../src/training-adjustments/training-adjustments.types'
import type { WeeklyPlan } from '../src/weekly-plan/weekly-plan.types'

describe('AiPlanService (openai provider; mocked)', () => {
  let service: any
  let mockResponsesCreate: jest.Mock
  let mockOpenAIConstructor: jest.Mock
  let mockAiCacheService: any
  let mockTrainingFeedbackV2Service: any

  beforeEach(() => {
    jest.resetModules()
    jest.resetAllMocks()

    mockResponsesCreate = jest.fn()
    mockOpenAIConstructor = jest.fn().mockImplementation(() => ({
      responses: { create: mockResponsesCreate },
    }))

    jest.doMock('openai', () => mockOpenAIConstructor)

    mockAiCacheService = {
      get: jest.fn().mockReturnValue(null),
      set: jest.fn(),
    }

    mockTrainingFeedbackV2Service = {
      getLatestFeedbackSignalsForUser: jest.fn().mockResolvedValue(undefined),
    }

    const { AiPlanService } = require('../src/ai-plan/ai-plan.service')
    service = new AiPlanService(mockAiCacheService, mockTrainingFeedbackV2Service)

    process.env.AI_PLAN_PROVIDER = 'openai'
    process.env.OPENAI_API_KEY = 'dummy'
    process.env.AI_PLAN_MODEL = 'gpt-5'
    process.env.AI_PLAN_TEMPERATURE = '0'
  })

  it('returns explanation from OpenAI JSON output_text', async () => {
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

    const mockExplanation = {
      titlePl: 'Plan tygodniowy (AI)',
      summaryPl: ['Punkt 1', 'Punkt 2', 'Punkt 3'],
      sessionNotesPl: [{ day: 'mon', text: 'Trzymaj się spokojnej strefy tętna.' }],
      warningsPl: ['Uwaga testowa'],
      confidence: 0.77,
    }

    mockResponsesCreate.mockResolvedValueOnce({
      output_text: JSON.stringify(mockExplanation),
    })

    const out = await service.buildResponse(1, ctx, adjustments, plan)

    expect(out.explanation).toEqual(mockExplanation)
    expect(out.provider).toBe('openai')
    expect(mockOpenAIConstructor).toHaveBeenCalledTimes(1)
    expect(mockResponsesCreate).toHaveBeenCalledTimes(1)
  })
})


