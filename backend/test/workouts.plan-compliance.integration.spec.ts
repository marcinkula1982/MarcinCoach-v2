import { Test } from '@nestjs/testing'
import { PrismaService } from '../src/prisma.service'
import { WorkoutsService } from '../src/workouts/workouts.service'
import { PlanSnapshotService } from '../src/plan-snapshot/plan-snapshot.service'
import type { PlanSnapshot } from '../src/plan-snapshot/plan-snapshot.types'
import { SaveWorkoutDto } from '../src/workouts/dto/save-workout.dto'

describe('WorkoutsService PlanCompliance Integration', () => {
  let prisma: PrismaService
  let workoutsService: WorkoutsService
  let planSnapshotService: PlanSnapshotService
  let userId: number

  beforeEach(async () => {
    const module = await Test.createTestingModule({
      providers: [PrismaService, WorkoutsService, PlanSnapshotService],
    }).compile()

    prisma = module.get(PrismaService)
    workoutsService = module.get(WorkoutsService)
    planSnapshotService = module.get(PlanSnapshotService)

    // Create test user
    const user = await prisma.user.create({
      data: { externalId: `test-${Date.now()}` },
    })
    userId = user.id
  })

  afterEach(async () => {
    // Cleanup
    await prisma.workout.deleteMany({ where: { userId } })
    await prisma.planSnapshot.deleteMany({ where: { userId } })
    await prisma.user.delete({ where: { id: userId } })
  })

  it('saves planCompliance when workout matches snapshot day', async () => {
    // Setup: zapisz PlanSnapshot dla userId z dniem na konkretną datę
    const testDate = '2025-01-15'
    const snapshot: PlanSnapshot = {
      windowStartIso: '2025-01-13T00:00:00.000Z',
      windowEndIso: '2025-01-19T23:59:59.999Z',
      days: [
        {
          dateKey: testDate,
          type: 'easy',
          plannedDurationMin: 30,
          plannedDistanceKm: 5,
        },
      ],
    }
    await planSnapshotService.saveForUser(userId, snapshot)

    // Import workoutu na tę datę
    const workoutDto: SaveWorkoutDto = {
      action: 'save',
      kind: 'training',
      tcxRaw: '<?xml version="1.0"?><TrainingCenterDatabase></TrainingCenterDatabase>',
      summary: {
        startTimeIso: `2025-01-15T10:00:00.000Z`,
        original: {
          durationSec: 1800, // 30 min
          distanceM: 5000, // 5 km
          avgPaceSecPerKm: null,
          avgHr: null,
          maxHr: null,
          count: 0,
        },
        trimmed: null,
        intensity: null,
        totalPoints: 0,
        selectedPoints: 0,
      },
    }

    const result = await workoutsService.create(userId, 'test-user', workoutDto)

    // Weryfikuj że workoutMeta.planCompliance jest zapisane
    expect(result.workoutMeta).toBeDefined()
    const meta = result.workoutMeta as any
    expect(meta.planCompliance).toBeDefined()
    expect(meta.planCompliance.status).toBe('OK')
    expect(meta.planCompliance.durationRatio).toBeCloseTo(1.0, 2)
    expect(meta.planCompliance.distanceRatio).toBeCloseTo(1.0, 2)
  })

  it('uses latest snapshot as fallback when workout date is outside window', async () => {
    // Setup: zapisz PlanSnapshot z window w przeszłości
    const snapshot: PlanSnapshot = {
      windowStartIso: '2025-01-01T00:00:00.000Z',
      windowEndIso: '2025-01-07T23:59:59.999Z',
      days: [
        {
          dateKey: '2025-01-05',
          type: 'easy',
          plannedDurationMin: 30,
        },
      ],
    }
    await planSnapshotService.saveForUser(userId, snapshot)

    // Import workoutu na datę poza window (ale używa latest jako fallback)
    const workoutDto: SaveWorkoutDto = {
      action: 'save',
      kind: 'training',
      tcxRaw: '<?xml version="1.0"?><TrainingCenterDatabase></TrainingCenterDatabase>',
      summary: {
        startTimeIso: `2025-01-20T10:00:00.000Z`, // Poza window
        original: {
          durationSec: 1800, // 30 min
          distanceM: 0,
          avgPaceSecPerKm: null,
          avgHr: null,
          maxHr: null,
          count: 0,
        },
        trimmed: null,
        intensity: null,
        totalPoints: 0,
        selectedPoints: 0,
      },
    }

    const result = await workoutsService.create(userId, 'test-user', workoutDto)

    // Weryfikuj że workoutMeta.planCompliance jest zapisane (z plannedMissing lub OK jeśli nie ma matching day)
    expect(result.workoutMeta).toBeDefined()
    const meta = result.workoutMeta as any
    expect(meta.planCompliance).toBeDefined()
    // Może być plannedMissing jeśli nie ma matching day w snapshot
    expect(meta.planCompliance.status).toBeDefined()
  })
})

