import { AiInsightsService } from '../src/ai-insights/ai-insights.service'
import { aiInsightsSchema } from '../src/ai-insights/ai-insights.schema'
import type { PlanFeedbackSignals } from '../src/training-feedback/training-feedback.types'

describe('AiInsightsService (stub)', () => {
  const mockTrainingFeedbackService = {
    getFeedbackForUser: jest.fn(),
  } as any

  const mockUserProfileService = {
    getConstraintsForUser: jest.fn(),
  } as any

  const mockAiCacheService = {
    get: jest.fn().mockReturnValue(undefined),
    set: jest.fn(),
  }

  const service = new AiInsightsService(mockTrainingFeedbackService, mockUserProfileService, mockAiCacheService as any)

  beforeEach(() => {
    process.env.AI_INSIGHTS_PROVIDER = 'stub'
    jest.resetAllMocks()
  })

  it('totalSessions=0 -> risks none, confidence 0.2, schema OK', async () => {
    const feedback: PlanFeedbackSignals = {
      generatedAtIso: new Date(0).toISOString(),
      windowDays: 28,
      counts: { totalSessions: 0, planned: 0, modified: 0, unplanned: 0, unknown: 0 },
      complianceRate: { plannedPct: 0, modifiedPct: 0, unplannedPct: 0 },
      rpe: { samples: 0 },
      fatigue: { trueCount: 0, falseCount: 0 },
      notes: { samples: 0, last5: [] },
    }

    mockTrainingFeedbackService.getFeedbackForUser.mockResolvedValueOnce(feedback)
    mockUserProfileService.getConstraintsForUser.mockResolvedValueOnce({
      timezone: 'Europe/Warsaw',
      runningDays: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
      surfaces: { preferTrail: true, avoidAsphalt: true },
      shoes: { avoidZeroDrop: true },
    })

    const result = await service.getInsightsForUser(1, 'marcin', { days: 28 })

    const parsed = aiInsightsSchema.safeParse(result.payload)
    expect(parsed.success).toBe(true)

    expect(result.payload.generatedAtIso).toBe(feedback.generatedAtIso)
    expect(result.payload.windowDays).toBe(28)
    expect(result.payload.risks).toEqual(['none'])
    expect(result.payload.confidence).toBe(0.2)
  })

  it('unplannedPct>=50 and fatigue.trueCount>=2 -> includes risks fatigue and low-compliance', async () => {
    const feedback: PlanFeedbackSignals = {
      generatedAtIso: '2025-01-01T00:00:00.000Z',
      windowDays: 28,
      counts: { totalSessions: 4, planned: 1, modified: 1, unplanned: 2, unknown: 0 },
      complianceRate: { plannedPct: 25, modifiedPct: 25, unplannedPct: 50 },
      rpe: { samples: 2, avg: 6.0, p50: 6.0 },
      fatigue: { trueCount: 2, falseCount: 2 },
      notes: { samples: 0, last5: [] },
    }

    mockTrainingFeedbackService.getFeedbackForUser.mockResolvedValueOnce(feedback)
    mockUserProfileService.getConstraintsForUser.mockResolvedValueOnce({
      timezone: 'Europe/Warsaw',
      runningDays: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
      surfaces: { preferTrail: true, avoidAsphalt: true },
      shoes: { avoidZeroDrop: true },
      hrZones: { z1: [100, 120], z2: [121, 140], z3: [141, 155], z4: [156, 170], z5: [171, 190] },
    })

    const result = await service.getInsightsForUser(1, 'marcin', { days: 28 })

    const parsed = aiInsightsSchema.safeParse(result.payload)
    expect(parsed.success).toBe(true)

    expect(result.payload.generatedAtIso).toBe(feedback.generatedAtIso)
    expect(result.payload.windowDays).toBe(28)
    expect(result.payload.risks).toEqual(expect.arrayContaining(['fatigue', 'low-compliance']))
  })
})


