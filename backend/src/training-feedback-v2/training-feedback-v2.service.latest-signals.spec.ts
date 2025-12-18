import { TrainingFeedbackV2Service } from './training-feedback-v2.service'
import { PrismaService } from '../prisma.service'
import { parseAndNormalizeFeedbackRecord } from './training-feedback-v2-normalize'
import { mapFeedbackToSignals } from './feedback-signals.mapper'

// Mock helper functions
jest.mock('./training-feedback-v2-normalize', () => ({
  parseAndNormalizeFeedbackRecord: jest.fn(),
}))

jest.mock('./feedback-signals.mapper', () => ({
  mapFeedbackToSignals: jest.fn(),
}))

describe('TrainingFeedbackV2Service - getLatestFeedbackSignalsForUser', () => {
  let service: TrainingFeedbackV2Service
  let prisma: PrismaService

  const mockFeedback = {
    character: 'easy' as const,
    hrStability: { drift: null, artefacts: false },
    economy: { paceEquality: 0.85, variance: 0.1 },
    loadImpact: { weeklyLoadContribution: 30, intensityScore: 100 },
    coachSignals: {
      character: 'easy' as const,
      hrStable: true,
      economyGood: true,
      loadHeavy: false,
    },
    metrics: {
      hrDrift: null,
      paceEquality: 0.85,
      weeklyLoadContribution: 30,
    },
    workoutId: 1,
  }

  const mockSignals = {
    intensityClass: 'easy' as const,
    hrStable: true,
    economyFlag: 'good' as const,
    loadImpact: 'medium' as const,
    warnings: {},
  }

  beforeEach(() => {
    prisma = {
      workout: {
        findFirst: jest.fn(),
      },
      trainingFeedbackV2: {
        findMany: jest.fn(),
        upsert: jest.fn(),
        findFirst: jest.fn(),
      },
    } as any

    service = new TrainingFeedbackV2Service(prisma)
    jest.clearAllMocks()
    ;(parseAndNormalizeFeedbackRecord as jest.Mock).mockReturnValue(mockFeedback)
    ;(mapFeedbackToSignals as jest.Mock).mockReturnValue(mockSignals)
  })

  it('returns undefined when no feedback exists', async () => {
    ;(prisma as any).trainingFeedbackV2.findMany.mockResolvedValue([])

    const result = await service.getLatestFeedbackSignalsForUser(123)

    expect(result).toBeUndefined()
    expect((prisma as any).trainingFeedbackV2.findMany).toHaveBeenCalledWith({
      where: { userId: 123 },
      include: {
        workout: {
          select: {
            summary: true,
            createdAt: true,
          },
        },
      },
      orderBy: { id: 'desc' },
      take: 50,
    })
  })

  it('selects record with latest startTimeIso when both have startTimeIso', async () => {
    const recordA = {
      id: 1,
      feedback: JSON.stringify(mockFeedback),
      workout: {
        summary: JSON.stringify({ startTimeIso: '2025-12-10T10:00:00Z' }),
        createdAt: new Date('2025-12-11T10:00:00Z'),
      },
    }

    const recordB = {
      id: 2,
      feedback: JSON.stringify(mockFeedback),
      workout: {
        summary: JSON.stringify({ startTimeIso: '2025-12-11T10:00:00Z' }),
        createdAt: new Date('2025-12-10T10:00:00Z'), // wcześniejszy createdAt
      },
    }

    ;(prisma as any).trainingFeedbackV2.findMany.mockResolvedValue([recordA, recordB])

    const result = await service.getLatestFeedbackSignalsForUser(123)

    expect(result).toEqual(mockSignals)
    expect(parseAndNormalizeFeedbackRecord).toHaveBeenCalledWith(recordB) // B ma późniejszy startTimeIso
    expect(mapFeedbackToSignals).toHaveBeenCalledWith(mockFeedback)
  })

  it('falls back to workout.createdAt when startTimeIso is missing', async () => {
    const recordA = {
      id: 1,
      feedback: JSON.stringify(mockFeedback),
      workout: {
        summary: JSON.stringify({}),
        createdAt: new Date('2025-12-10T10:00:00Z'),
      },
    }

    const recordB = {
      id: 2,
      feedback: JSON.stringify(mockFeedback),
      workout: {
        summary: JSON.stringify({}),
        createdAt: new Date('2025-12-11T10:00:00Z'),
      },
    }

    ;(prisma as any).trainingFeedbackV2.findMany.mockResolvedValue([recordA, recordB])

    const result = await service.getLatestFeedbackSignalsForUser(123)

    expect(result).toEqual(mockSignals)
    expect(parseAndNormalizeFeedbackRecord).toHaveBeenCalledWith(recordB) // B ma późniejszy createdAt
  })

  it('uses tie-breaker (larger id) when workoutDt is equal', async () => {
    const sameTime = '2025-12-10T10:00:00Z'
    const recordA = {
      id: 1,
      feedback: JSON.stringify(mockFeedback),
      workout: {
        summary: JSON.stringify({ startTimeIso: sameTime }),
        createdAt: new Date(sameTime),
      },
    }

    const recordB = {
      id: 2,
      feedback: JSON.stringify(mockFeedback),
      workout: {
        summary: JSON.stringify({ startTimeIso: sameTime }),
        createdAt: new Date(sameTime),
      },
    }

    ;(prisma as any).trainingFeedbackV2.findMany.mockResolvedValue([recordA, recordB])

    const result = await service.getLatestFeedbackSignalsForUser(123)

    expect(result).toEqual(mockSignals)
    expect(parseAndNormalizeFeedbackRecord).toHaveBeenCalledWith(recordB) // B ma większe id
  })

  it('skips invalid feedback and tries next best candidate', async () => {
    const recordA = {
      id: 1,
      feedback: JSON.stringify(mockFeedback),
      workout: {
        summary: JSON.stringify({ startTimeIso: '2025-12-11T10:00:00Z' }),
        createdAt: new Date('2025-12-11T10:00:00Z'),
      },
    }

    const recordB = {
      id: 2,
      feedback: JSON.stringify(mockFeedback),
      workout: {
        summary: JSON.stringify({ startTimeIso: '2025-12-10T10:00:00Z' }),
        createdAt: new Date('2025-12-10T10:00:00Z'),
      },
    }

    ;(prisma as any).trainingFeedbackV2.findMany.mockResolvedValue([recordA, recordB])
    ;(parseAndNormalizeFeedbackRecord as jest.Mock)
      .mockReturnValueOnce(null) // recordA zwraca null
      .mockReturnValueOnce(mockFeedback) // recordB zwraca poprawny feedback

    const result = await service.getLatestFeedbackSignalsForUser(123)

    expect(result).toEqual(mockSignals)
    expect(parseAndNormalizeFeedbackRecord).toHaveBeenCalledTimes(2)
    expect(parseAndNormalizeFeedbackRecord).toHaveBeenCalledWith(recordA)
    expect(parseAndNormalizeFeedbackRecord).toHaveBeenCalledWith(recordB)
  })

  it('returns undefined when all candidates have invalid feedback', async () => {
    const recordA = {
      id: 1,
      feedback: JSON.stringify(mockFeedback),
      workout: {
        summary: JSON.stringify({ startTimeIso: '2025-12-11T10:00:00Z' }),
        createdAt: new Date('2025-12-11T10:00:00Z'),
      },
    }

    ;(prisma as any).trainingFeedbackV2.findMany.mockResolvedValue([recordA])
    ;(parseAndNormalizeFeedbackRecord as jest.Mock).mockReturnValue(null)

    const result = await service.getLatestFeedbackSignalsForUser(123)

    expect(result).toBeUndefined()
  })

  it('handles invalid startTimeIso and falls back to createdAt', async () => {
    const recordA = {
      id: 1,
      feedback: JSON.stringify(mockFeedback),
      workout: {
        summary: JSON.stringify({ startTimeIso: 'invalid-iso' }),
        createdAt: new Date('2025-12-10T10:00:00Z'),
      },
    }

    const recordB = {
      id: 2,
      feedback: JSON.stringify(mockFeedback),
      workout: {
        summary: JSON.stringify({ startTimeIso: 'invalid-iso' }),
        createdAt: new Date('2025-12-11T10:00:00Z'),
      },
    }

    ;(prisma as any).trainingFeedbackV2.findMany.mockResolvedValue([recordA, recordB])

    const result = await service.getLatestFeedbackSignalsForUser(123)

    expect(result).toEqual(mockSignals)
    expect(parseAndNormalizeFeedbackRecord).toHaveBeenCalledWith(recordB) // B ma późniejszy createdAt
  })
})

