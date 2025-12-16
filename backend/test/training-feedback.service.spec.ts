import { TrainingFeedbackService } from '../src/training-feedback/training-feedback.service'
import { PrismaService } from '../src/prisma.service'
import { planFeedbackSignalsSchema } from '../src/training-feedback/training-feedback.schema'

describe('TrainingFeedbackService', () => {
  const prisma = new PrismaService()
  const service = new TrainingFeedbackService(prisma)

  it('returns zeros for empty workouts', async () => {
    // mock prisma.findMany to return empty
    jest.spyOn(prisma.workout, 'findMany').mockResolvedValueOnce([])

    const result = await service.getFeedbackForUser(1, { days: 28 })

    const parsed = planFeedbackSignalsSchema.safeParse(result)
    expect(parsed.success).toBe(true)

    expect(result.counts.totalSessions).toBe(0)
    expect(result.counts.planned).toBe(0)
    expect(result.counts.modified).toBe(0)
    expect(result.counts.unplanned).toBe(0)
    expect(result.counts.unknown).toBe(0)

    expect(result.complianceRate.plannedPct).toBe(0)
    expect(result.complianceRate.modifiedPct).toBe(0)
    expect(result.complianceRate.unplannedPct).toBe(0)

    expect(result.rpe.samples).toBe(0)
    expect(result.rpe.avg).toBeUndefined()
    expect(result.rpe.p50).toBeUndefined()

    expect(result.fatigue.trueCount).toBe(0)
    expect(result.fatigue.falseCount).toBe(0)

    expect(result.notes.samples).toBe(0)
    expect(result.notes.last5).toEqual([])

    // generatedAtIso should be new Date(0).toISOString() for empty list
    expect(result.generatedAtIso).toBe(new Date(0).toISOString())
  })

  it('computes feedback correctly for 4 workouts with different compliance, rpe, fatigue, and notes', async () => {
    const now = new Date()
    const workout1Dt = new Date(now.getTime() - 5 * 24 * 60 * 60 * 1000) // 5 days ago
    const workout2Dt = new Date(now.getTime() - 10 * 24 * 60 * 60 * 1000) // 10 days ago
    const workout3Dt = new Date(now.getTime() - 15 * 24 * 60 * 60 * 1000) // 15 days ago
    const workout4Dt = new Date(now.getTime() - 20 * 24 * 60 * 60 * 1000) // 20 days ago

    // Max workoutDt will be workout1Dt (most recent)
    const maxWorkoutDt = workout1Dt

    const workout1 = {
      id: 1,
      createdAt: workout1Dt,
      summary: JSON.stringify({
        startTimeIso: workout1Dt.toISOString(),
      }),
      workoutMeta: JSON.stringify({
        planCompliance: 'planned',
        rpe: 5,
        fatigueFlag: true,
        note: 'Note 1',
      }),
    }

    const workout2 = {
      id: 2,
      createdAt: workout2Dt,
      summary: JSON.stringify({
        startTimeIso: workout2Dt.toISOString(),
      }),
      workoutMeta: JSON.stringify({
        planCompliance: 'modified',
        rpe: 7,
        fatigueFlag: false,
        note: 'Note 2',
      }),
    }

    const workout3 = {
      id: 3,
      createdAt: workout3Dt,
      summary: JSON.stringify({
        startTimeIso: workout3Dt.toISOString(),
      }),
      workoutMeta: JSON.stringify({
        planCompliance: 'unplanned',
        // no rpe
        fatigueFlag: true,
        // no note
      }),
    }

    const workout4 = {
      id: 4,
      createdAt: workout4Dt,
      summary: JSON.stringify({
        startTimeIso: workout4Dt.toISOString(),
      }),
      workoutMeta: JSON.stringify({
        planCompliance: null, // should map to 'unknown'
        // no rpe
        fatigueFlag: false,
        note: 'Note 3',
      }),
    }

    jest
      .spyOn(prisma.workout, 'findMany')
      .mockResolvedValueOnce([workout1, workout2, workout3, workout4] as any)

    const result = await service.getFeedbackForUser(1, { days: 28 })

    const parsed = planFeedbackSignalsSchema.safeParse(result)
    expect(parsed.success).toBe(true)

    // Counts: planned=1, modified=1, unplanned=1, unknown=1, total=4
    expect(result.counts.totalSessions).toBe(4)
    expect(result.counts.planned).toBe(1)
    expect(result.counts.modified).toBe(1)
    expect(result.counts.unplanned).toBe(1)
    expect(result.counts.unknown).toBe(1)

    // Compliance rates: 25.00% each
    expect(result.complianceRate.plannedPct).toBe(25.0)
    expect(result.complianceRate.modifiedPct).toBe(25.0)
    expect(result.complianceRate.unplannedPct).toBe(25.0)

    // RPE: samples=2 (workout1: 5, workout2: 7), avg=6.0, p50=6.0 (median of [5, 7])
    expect(result.rpe.samples).toBe(2)
    expect(result.rpe.avg).toBe(6.0)
    expect(result.rpe.p50).toBe(6.0)

    // Fatigue: trueCount=2 (workout1, workout3), falseCount=2 (workout2, workout4)
    expect(result.fatigue.trueCount).toBe(2)
    expect(result.fatigue.falseCount).toBe(2)

    // Notes: samples=3 (workout1, workout2, workout4), last5 should have 3 items sorted desc by workoutDt
    expect(result.notes.samples).toBe(3)
    expect(result.notes.last5).toHaveLength(3)
    // Should be sorted desc by workoutDt: workout1 (most recent), workout2, workout4
    expect(result.notes.last5[0]?.workoutId).toBe(1)
    expect(result.notes.last5[0]?.note).toBe('Note 1')
    expect(result.notes.last5[1]?.workoutId).toBe(2)
    expect(result.notes.last5[1]?.note).toBe('Note 2')
    expect(result.notes.last5[2]?.workoutId).toBe(4)
    expect(result.notes.last5[2]?.note).toBe('Note 3')

    // generatedAtIso should equal max(workoutDt).toISOString()
    expect(result.generatedAtIso).toBe(maxWorkoutDt.toISOString())
  })
})

