import { TrainingAdjustmentsService } from '../src/training-adjustments/training-adjustments.service'
import type { TrainingContext } from '../src/training-context/training-context.types'

describe('TrainingAdjustmentsService', () => {
  const service = new TrainingAdjustmentsService()

  const baseContext = (overrides?: Partial<TrainingContext>): TrainingContext => {
    return {
      generatedAtIso: '2024-01-15T12:00:00.000Z',
      windowDays: 28,
      signals: {
        period: { from: '2024-01-01T00:00:00.000Z', to: '2024-01-15T12:00:00.000Z' },
        volume: { distanceKm: 0, durationMin: 0, sessions: 0 },
        intensity: { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0, totalSec: 0 },
        longRun: { exists: true, distanceKm: 0, durationMin: 0, workoutId: null, workoutDt: null },
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
      ...overrides,
    }
  }

  it('returns empty adjustments when no rules match', () => {
    const context = baseContext({
      signals: {
        ...baseContext().signals,
        flags: { injuryRisk: false, fatigue: false },
        longRun: { ...baseContext().signals.longRun, exists: true },
      },
      profile: {
        ...baseContext().profile,
        surfaces: { preferTrail: false, avoidAsphalt: false },
      },
    })

    const result = service.generate(context)
    expect(result.adjustments).toHaveLength(0)
  })

  it('adds adjustments in deterministic order for fatigue + missing long run + avoid asphalt', () => {
    const context = baseContext({
      generatedAtIso: '2024-02-20T15:30:00.000Z',
      windowDays: 14,
      signals: {
        ...baseContext().signals,
        flags: { injuryRisk: false, fatigue: true },
        longRun: { ...baseContext().signals.longRun, exists: false },
      },
      profile: {
        ...baseContext().profile,
        surfaces: { preferTrail: false, avoidAsphalt: true },
      },
    })

    const result = service.generate(context)
    expect(result.adjustments.map((a) => a.code)).toEqual([
      'reduce_load',
      'add_long_run',
      'surface_constraint',
    ])
    expect(result.generatedAtIso).toBe(context.generatedAtIso)
    expect(result.windowDays).toBe(context.windowDays)
  })
})


