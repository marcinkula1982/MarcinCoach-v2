import { TrainingFeedbackV2Service } from './training-feedback-v2.service'
import { PrismaService } from '../prisma.service'
import { presentFeedback } from './training-feedback-v2-presenter'

describe('TrainingFeedbackV2Service', () => {
  let service: TrainingFeedbackV2Service
  let prisma: PrismaService

  beforeEach(() => {
    prisma = {
      workout: {
        findFirst: jest.fn(),
      },
      trainingFeedbackV2: {
        upsert: jest.fn(),
        findFirst: jest.fn(),
      },
    } as any

    service = new TrainingFeedbackV2Service(prisma)
  })

  describe('getFeedbackForWorkout - normalization', () => {
    it('normalizes old snapshot with fatigueRisk/readiness/trainingRole', async () => {
      const oldSnapshot = {
        id: 1,
        workoutId: 123,
        userId: 456,
        feedback: JSON.stringify({
          character: 'easy',
          hrStability: { drift: 1, artefacts: false },
          economy: { paceEquality: 0.9, variance: 10 },
          loadImpact: { weeklyLoadContribution: 30, intensityScore: 500 },
          coachSignals: {
            fatigueRisk: 'low',
            readiness: 'high',
            trainingRole: 'base',
          },
          coachConclusion: 'Trening łatwy. stabilne tempo.',
          metrics: {
            hrDrift: 1,
            paceEquality: 0.9,
            weeklyLoadContribution: 30,
          },
          workoutId: 123,
        }),
        createdAt: new Date(),
        updatedAt: new Date(),
      }

      ;(prisma as any).trainingFeedbackV2.findFirst.mockResolvedValue(oldSnapshot)

      const result = await service.getFeedbackForWorkout(123, 456)

      expect(result).not.toBeNull()
      expect(result?.feedback.coachSignals).toEqual({
        character: 'easy',
        hrStable: true,
        economyGood: true,
        loadHeavy: false,
      })
      expect(result?.feedback.metrics).toEqual({
        hrDrift: 1,
        paceEquality: 0.9,
        weeklyLoadContribution: 30,
      })
      expect(result?.createdAt).toBeInstanceOf(Date)
      // coachConclusion nie jest w DB snapshot - generowane w presenterze
    })

    it('normalizes snapshot without coachSignals', async () => {
      const snapshotWithoutSignals = {
        id: 1,
        workoutId: 123,
        userId: 456,
        feedback: JSON.stringify({
          character: 'tempo',
          hrStability: { drift: null, artefacts: false },
          economy: { paceEquality: 0.3, variance: 100 },
          loadImpact: { weeklyLoadContribution: 40, intensityScore: 500 },
          metrics: {
            hrDrift: null,
            paceEquality: 0.3,
            weeklyLoadContribution: 40,
          },
          workoutId: 123,
        }),
        createdAt: new Date(),
        updatedAt: new Date(),
      }

      ;(prisma as any).trainingFeedbackV2.findFirst.mockResolvedValue(snapshotWithoutSignals)

      const result = await service.getFeedbackForWorkout(123, 456)

      expect(result).not.toBeNull()
      expect(result?.feedback.coachSignals).toEqual({
        character: 'tempo',
        hrStable: true,
        economyGood: false,
        loadHeavy: false,
      })
      expect(result?.createdAt).toBeInstanceOf(Date)
    })

    it('normalizes snapshot with missing boolean fields', async () => {
      const incompleteSnapshot = {
        id: 1,
        workoutId: 123,
        userId: 456,
        feedback: JSON.stringify({
          character: 'interwał',
          hrStability: { drift: 3, artefacts: false },
          economy: { paceEquality: 0.7, variance: 50 },
          loadImpact: { weeklyLoadContribution: 60, intensityScore: 800 },
          coachSignals: {
            character: 'interwał',
            // brak boolean pól
          },
          metrics: {
            hrDrift: 3,
            paceEquality: 0.7,
            weeklyLoadContribution: 60,
          },
          workoutId: 123,
        }),
        createdAt: new Date(),
        updatedAt: new Date(),
      }

      ;(prisma as any).trainingFeedbackV2.findFirst.mockResolvedValue(incompleteSnapshot)

      const result = await service.getFeedbackForWorkout(123, 456)

      expect(result).not.toBeNull()
      expect(result?.feedback.coachSignals).toEqual({
        character: 'interwał',
        hrStable: false, // drift > 2
        economyGood: false, // paceEquality <= 0.8
        loadHeavy: true, // weeklyLoadContribution > 50
      })
      expect(result?.createdAt).toBeInstanceOf(Date)
    })

    it('handles missing data gracefully', async () => {
      const minimalSnapshot = {
        id: 1,
        workoutId: 123,
        userId: 456,
        feedback: JSON.stringify({
          character: 'easy',
          hrStability: { drift: null, artefacts: false },
          economy: { paceEquality: 0.5, variance: 0 },
          loadImpact: { weeklyLoadContribution: 0, intensityScore: 0 },
          metrics: {
            hrDrift: null,
            paceEquality: 0.5,
            weeklyLoadContribution: 0,
          },
          workoutId: 123,
        }),
        createdAt: new Date(),
        updatedAt: new Date(),
      }

      ;(prisma as any).trainingFeedbackV2.findFirst.mockResolvedValue(minimalSnapshot)

      const result = await service.getFeedbackForWorkout(123, 456)

      expect(result).not.toBeNull()
      expect(result?.feedback.coachSignals).toEqual({
        character: 'easy',
        hrStable: true, // drift null i brak artefaktów -> true
        economyGood: false, // paceEquality 0.5 <= 0.8 -> false
        loadHeavy: false, // weeklyLoadContribution 0 <= 50 -> false
      })
      expect(result?.feedback.metrics).toEqual({
        hrDrift: null,
        paceEquality: 0.5,
        weeklyLoadContribution: 0,
      })
      expect(result?.createdAt).toBeInstanceOf(Date)
    })

    it('normalizes snapshot without metrics', async () => {
      const snapshotWithoutMetrics = {
        id: 1,
        workoutId: 123,
        userId: 456,
        feedback: JSON.stringify({
          character: 'easy',
          hrStability: { drift: 1.5, artefacts: false },
          economy: { paceEquality: 0.85, variance: 15 },
          loadImpact: { weeklyLoadContribution: 25, intensityScore: 400 },
          coachSignals: {
            character: 'easy',
            hrStable: true,
            economyGood: true,
            loadHeavy: false,
          },
          workoutId: 123,
        }),
        createdAt: new Date(),
        updatedAt: new Date(),
      }

      ;(prisma as any).trainingFeedbackV2.findFirst.mockResolvedValue(snapshotWithoutMetrics)

      const result = await service.getFeedbackForWorkout(123, 456)

      expect(result).not.toBeNull()
      expect(result?.feedback.metrics).toEqual({
        hrDrift: 1.5,
        paceEquality: 0.85,
        weeklyLoadContribution: 25,
      })
      expect(result?.createdAt).toBeInstanceOf(Date)
    })

    it('removes coachConclusion from snapshot', async () => {
      const snapshotWithConclusion = {
        id: 1,
        workoutId: 123,
        userId: 456,
        feedback: JSON.stringify({
          character: 'easy',
          hrStability: { drift: null, artefacts: false },
          economy: { paceEquality: 0.9, variance: 10 },
          loadImpact: { weeklyLoadContribution: 30, intensityScore: 500 },
          coachSignals: {
            character: 'easy',
            hrStable: true,
            economyGood: true,
            loadHeavy: false,
          },
          coachConclusion: 'Trening łatwy. stabilne tempo.',
          metrics: {
            hrDrift: null,
            paceEquality: 0.9,
            weeklyLoadContribution: 30,
          },
          workoutId: 123,
        }),
        createdAt: new Date(),
        updatedAt: new Date(),
      }

      ;(prisma as any).trainingFeedbackV2.findFirst.mockResolvedValue(snapshotWithConclusion)

      const result = await service.getFeedbackForWorkout(123, 456)

      expect(result).not.toBeNull()
      expect(result?.feedback.metrics).toEqual({
        hrDrift: null,
        paceEquality: 0.9,
        weeklyLoadContribution: 30,
      })
      expect(result?.createdAt).toBeInstanceOf(Date)
      // coachConclusion nie jest w DB snapshot - generowane w presenterze
    })
  })

  describe('getFeedbackForWorkout - response compatibility', () => {
    it('returns feedback that can be presented with coachConclusion', async () => {
      const snapshot = {
        id: 1,
        workoutId: 123,
        userId: 456,
        feedback: JSON.stringify({
          character: 'easy',
          hrStability: { drift: null, artefacts: false },
          economy: { paceEquality: 0.9, variance: 10 },
          loadImpact: { weeklyLoadContribution: 30, intensityScore: 500 },
          coachSignals: {
            character: 'easy',
            hrStable: true,
            economyGood: true,
            loadHeavy: false,
          },
          metrics: {
            hrDrift: null,
            paceEquality: 0.9,
            weeklyLoadContribution: 30,
          },
          workoutId: 123,
        }),
        createdAt: new Date(),
        updatedAt: new Date(),
      }

      ;(prisma as any).trainingFeedbackV2.findFirst.mockResolvedValue(snapshot)

      const result = await service.getFeedbackForWorkout(123, 456)
      const presented = presentFeedback(result!.feedback, result!.createdAt)

      expect(presented.coachConclusion).toBeTruthy()
      expect(presented.coachConclusion.length).toBeGreaterThan(0)
      expect(presented.generatedAtIso).toBeTruthy()
    })
  })
})

