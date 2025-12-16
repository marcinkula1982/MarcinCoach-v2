import { TrainingSignalsService } from '../src/training-signals/training-signals.service'
import { PrismaService } from '../src/prisma.service'
import { trainingSignalsSchema } from '../src/training-signals/training-signals.schema'

describe('TrainingSignalsService', () => {
  const prisma = new PrismaService()
  const service = new TrainingSignalsService(prisma)

  it('returns zeros for empty workouts', async () => {
    // mock prisma.findMany to return empty
    jest.spyOn(prisma.workout, 'findMany').mockResolvedValueOnce([])

    const result = await service.getSignalsForUser(1, { days: 28 })

    const parsed = trainingSignalsSchema.safeParse(result)
    expect(parsed.success).toBe(true)

    expect(result.volume.sessions).toBe(0)
    expect(result.volume.distanceKm).toBe(0)
    expect(result.volume.durationMin).toBe(0)
    expect(result.intensity.totalSec).toBe(0)
    expect(result.intensity.z1Sec).toBe(0)
    expect(result.intensity.z2Sec).toBe(0)
    expect(result.intensity.z3Sec).toBe(0)
    expect(result.intensity.z4Sec).toBe(0)
    expect(result.intensity.z5Sec).toBe(0)
    expect(result.longRun.exists).toBe(false)
    expect(result.longRun.workoutId).toBeNull()
    expect(result.longRun.workoutDt).toBeNull()
    expect(result.load.weeklyLoad).toBe(0)
    expect(result.load.rolling4wLoad).toBe(0)
    expect(result.flags.fatigue).toBe(false)
    expect(result.flags.injuryRisk).toBe(false)
  })

  it('computes load from summary.intensity (number), not from buckets', async () => {
    // Note: weeklyLoad is calculated relative to window 'to' (which defaults to new Date())
    // In this test, 'to' will be approximately 'now', so workout1 (3 days ago) is within last 7 days
    const now = new Date()
    const workout1 = {
      id: 1,
      createdAt: new Date(now.getTime() - 3 * 24 * 60 * 60 * 1000), // 3 days ago
      summary: JSON.stringify({
        startTimeIso: new Date(now.getTime() - 3 * 24 * 60 * 60 * 1000).toISOString(),
        trimmed: { distanceM: 5000, durationSec: 1200 },
        intensity: 150, // number, not buckets
      }),
    }
    const workout2 = {
      id: 2,
      createdAt: new Date(now.getTime() - 10 * 24 * 60 * 60 * 1000), // 10 days ago (outside weekly window relative to 'to')
      summary: JSON.stringify({
        startTimeIso: new Date(now.getTime() - 10 * 24 * 60 * 60 * 1000).toISOString(),
        trimmed: { distanceM: 3000, durationSec: 900 },
        intensity: 100, // number
      }),
    }

    jest.spyOn(prisma.workout, 'findMany').mockResolvedValueOnce([workout1, workout2] as any)

    const result = await service.getSignalsForUser(1, { days: 28 })

    // weeklyLoad is calculated relative to window 'to' (last 7 days from 'to')
    // Should only include workout1 (within last 7 days from 'to')
    expect(result.load.weeklyLoad).toBe(150)
    // rolling4wLoad includes all workouts in the 28-day window
    expect(result.load.rolling4wLoad).toBe(250)
  })

  it('handles missing buckets gracefully (zones=0, totalSec=durationSec)', async () => {
    const now = new Date()
    const workout = {
      id: 1,
      createdAt: now,
      summary: JSON.stringify({
        startTimeIso: now.toISOString(),
        trimmed: { distanceM: 5000, durationSec: 1200 },
        // no intensityBuckets, no intensity object
      }),
    }

    jest.spyOn(prisma.workout, 'findMany').mockResolvedValueOnce([workout] as any)

    const result = await service.getSignalsForUser(1, { days: 28 })

    expect(result.intensity.z1Sec).toBe(0)
    expect(result.intensity.z2Sec).toBe(0)
    expect(result.intensity.z3Sec).toBe(0)
    expect(result.intensity.z4Sec).toBe(0)
    expect(result.intensity.z5Sec).toBe(0)
    expect(result.intensity.totalSec).toBe(1200) // durationSec
  })
})

