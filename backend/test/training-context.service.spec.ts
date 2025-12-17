import { TrainingContextService } from '../src/training-context/training-context.service'
import { TrainingSignalsService } from '../src/training-signals/training-signals.service'
import { UserProfileService } from '../src/user-profile/user-profile.service'
import { trainingContextSchema } from '../src/training-context/training-context.schema'

describe('TrainingContextService', () => {
  const mockSignalsService = {
    getSignalsForUser: jest.fn(),
  } as unknown as TrainingSignalsService

  const mockProfileService = {
    getConstraintsForUser: jest.fn(),
  } as unknown as UserProfileService

  const service = new TrainingContextService(mockSignalsService, mockProfileService)

  it('returns valid TrainingContext with default profile', async () => {
    const mockSignals = {
      period: { from: '2024-01-01T00:00:00.000Z', to: '2024-01-28T00:00:00.000Z' },
      volume: { distanceKm: 0, durationMin: 0, sessions: 0 },
      intensity: { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0, totalSec: 0 },
      longRun: { exists: false, distanceKm: 0, durationMin: 0, workoutId: null, workoutDt: null },
      load: { weeklyLoad: 0, rolling4wLoad: 0 },
      consistency: { sessionsPerWeek: 0, streakWeeks: 0 },
      flags: { injuryRisk: false, fatigue: false },
    }

    const mockProfile = {
      timezone: 'Europe/Warsaw',
      runningDays: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
      surfaces: { preferTrail: true, avoidAsphalt: true },
      shoes: { avoidZeroDrop: true },
      hrZones: {
        z1: [0, 0],
        z2: [0, 0],
        z3: [0, 0],
        z4: [0, 0],
        z5: [0, 0],
      },
    }

    jest.spyOn(mockSignalsService, 'getSignalsForUser').mockResolvedValueOnce(mockSignals as any)
    jest.spyOn(mockProfileService, 'getConstraintsForUser').mockResolvedValueOnce(mockProfile as any)

    const result = await service.getContextForUser(1, { days: 28 })

    // Schema validation
    const parsed = trainingContextSchema.safeParse(result)
    expect(parsed.success).toBe(true)

    // Deterministic generatedAtIso
    expect(result.generatedAtIso).toBe(mockSignals.period.to)

    // Window days
    expect(result.windowDays).toBe(28)

    // Signals match
    expect(result.signals).toEqual(mockSignals)

    // Profile match
    expect(result.profile).toEqual(mockProfile)
  })

  it('uses default days (28) when not provided', async () => {
    const mockSignals = {
      period: { from: '2024-01-01T00:00:00.000Z', to: '2024-01-28T00:00:00.000Z' },
      volume: { distanceKm: 0, durationMin: 0, sessions: 0 },
      intensity: { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0, totalSec: 0 },
      longRun: { exists: false, distanceKm: 0, durationMin: 0, workoutId: null, workoutDt: null },
      load: { weeklyLoad: 0, rolling4wLoad: 0 },
      consistency: { sessionsPerWeek: 0, streakWeeks: 0 },
      flags: { injuryRisk: false, fatigue: false },
    }

    const mockProfile = {
      timezone: 'Europe/Warsaw',
      runningDays: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
      surfaces: { preferTrail: true, avoidAsphalt: true },
      shoes: { avoidZeroDrop: true },
      hrZones: {
        z1: [0, 0],
        z2: [0, 0],
        z3: [0, 0],
        z4: [0, 0],
        z5: [0, 0],
      },
    }

    jest.spyOn(mockSignalsService, 'getSignalsForUser').mockResolvedValueOnce(mockSignals as any)
    jest.spyOn(mockProfileService, 'getConstraintsForUser').mockResolvedValueOnce(mockProfile as any)

    const result = await service.getContextForUser(1)

    expect(result.windowDays).toBe(28)
    expect(mockSignalsService.getSignalsForUser).toHaveBeenCalledWith(1, { days: 28 })
  })
})

