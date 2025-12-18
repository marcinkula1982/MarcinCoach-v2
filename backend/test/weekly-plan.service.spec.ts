import { WeeklyPlanService } from '../src/weekly-plan/weekly-plan.service'
import type { TrainingContext } from '../src/training-context/training-context.types'
import type { TrainingAdjustments } from '../src/training-adjustments/training-adjustments.types'

describe('WeeklyPlanService - adjustments application', () => {
  const service = new WeeklyPlanService()

  const baseContext = (overrides?: Partial<TrainingContext>): TrainingContext => ({
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
    ...overrides,
  })

  it('applies reduce_load: removes quality sessions and replaces with easy (40 min)', () => {
    const context = baseContext({
      signals: {
        ...baseContext().signals,
        volume: { distanceKm: 100, durationMin: 400, sessions: 4 },
        flags: { injuryRisk: false, fatigue: false },
      },
    })

    const adjustments: TrainingAdjustments = {
      generatedAtIso: context.generatedAtIso,
      windowDays: context.windowDays,
      adjustments: [
        {
          code: 'reduce_load',
          severity: 'high',
          rationale: 'Test',
          evidence: [],
          params: { reductionPct: 25 },
        },
      ],
    }

    const plan = service.generatePlan(context, adjustments)

    // Sprawdź że nie ma sesji typu 'quality'
    expect(plan.sessions.every((s) => s.type !== 'quality')).toBe(true)
    // Sprawdź że wszystkie sesje które były quality są teraz easy z durationMin: 40
    const easySessions = plan.sessions.filter((s) => s.type === 'easy')
    easySessions.forEach((s) => {
      if (s.durationMin > 0) {
        // Easy sessions powinny mieć zmniejszony czas (75% z reductionPct: 25)
        expect(s.durationMin).toBeLessThanOrEqual(40)
      }
    })
  })

  it('applies recovery_focus: replaces quality session with easy', () => {
    const context = baseContext({
      signals: {
        ...baseContext().signals,
        volume: { distanceKm: 100, durationMin: 400, sessions: 4 },
        flags: { injuryRisk: false, fatigue: false },
      },
    })

    const adjustments: TrainingAdjustments = {
      generatedAtIso: context.generatedAtIso,
      windowDays: context.windowDays,
      adjustments: [
        {
          code: 'recovery_focus',
          severity: 'high',
          rationale: 'Test',
          evidence: [],
          params: { replaceHardSessionWithEasy: true },
        },
      ],
    }

    const plan = service.generatePlan(context, adjustments)

    // Sprawdź że nie ma sesji typu 'quality'
    expect(plan.sessions.every((s) => s.type !== 'quality')).toBe(true)
  })

  it('applies recovery_focus: reduces long run duration by 15%', () => {
    const context = baseContext({
      signals: {
        ...baseContext().signals,
        volume: { distanceKm: 100, durationMin: 400, sessions: 4 },
        flags: { injuryRisk: false, fatigue: false },
      },
    })

    const adjustments: TrainingAdjustments = {
      generatedAtIso: context.generatedAtIso,
      windowDays: context.windowDays,
      adjustments: [
        {
          code: 'recovery_focus',
          severity: 'high',
          rationale: 'Test',
          evidence: [],
          params: { longRunReductionPct: 15 },
        },
      ],
    }

    const plan = service.generatePlan(context, adjustments)
    const longRun = plan.sessions.find((s) => s.type === 'long')

    if (longRun) {
      // Long run powinien być skrócony o 15% (z 90 min do ~77 min, zaokrąglone do 5 min = 75 min)
      expect(longRun.durationMin).toBeLessThan(90)
      expect(longRun.durationMin).toBeGreaterThanOrEqual(75)
    }
  })

  it('applies technique_focus: adds strides notes to easy sessions', () => {
    const context = baseContext({
      signals: {
        ...baseContext().signals,
        volume: { distanceKm: 100, durationMin: 400, sessions: 2 }, // Zmniejsz sessions aby uniknąć automatycznego dodawania strides
        flags: { injuryRisk: false, fatigue: false },
      },
      profile: {
        ...baseContext().profile,
        runningDays: ['mon', 'wed'], // Tylko 2 dni - canHaveStrides będzie false
      },
    })

    const adjustments: TrainingAdjustments = {
      generatedAtIso: context.generatedAtIso,
      windowDays: context.windowDays,
      adjustments: [
        {
          code: 'technique_focus',
          severity: 'medium',
          rationale: 'Test',
          evidence: [],
          params: { addStrides: true, stridesCount: 6, stridesDurationSec: 20 },
        },
      ],
    }

    const plan = service.generatePlan(context, adjustments)
    const easySessionsWithStrides = plan.sessions.filter(
      (s) => s.type === 'easy' && s.notes?.some((note) => note.toLowerCase().includes('strides')),
    )

    // Powinno być 1-2 easy sessions ze strides z technique_focus
    expect(easySessionsWithStrides.length).toBeGreaterThanOrEqual(1)
    expect(easySessionsWithStrides.length).toBeLessThanOrEqual(2)

    // Sprawdź format notes - powinien zawierać "6x20s strides"
    easySessionsWithStrides.forEach((s) => {
      const stridesNote = s.notes?.find((note) => note.toLowerCase().includes('strides'))
      expect(stridesNote).toBeDefined()
      expect(stridesNote).toContain('6x20s strides')
    })
  })

  it('applies both reduce_load and recovery_focus deterministically', () => {
    const context = baseContext({
      signals: {
        ...baseContext().signals,
        volume: { distanceKm: 100, durationMin: 400, sessions: 4 },
        flags: { injuryRisk: false, fatigue: false },
      },
    })

    const adjustments: TrainingAdjustments = {
      generatedAtIso: context.generatedAtIso,
      windowDays: context.windowDays,
      adjustments: [
        {
          code: 'reduce_load',
          severity: 'high',
          rationale: 'Test',
          evidence: [],
          params: { reductionPct: 25 },
        },
        {
          code: 'recovery_focus',
          severity: 'high',
          rationale: 'Test',
          evidence: [],
          params: { replaceHardSessionWithEasy: true, longRunReductionPct: 15 },
        },
      ],
    }

    const plan = service.generatePlan(context, adjustments)

    // Oba adjustments powinny być zastosowane
    expect(plan.sessions.every((s) => s.type !== 'quality')).toBe(true)
    const longRun = plan.sessions.find((s) => s.type === 'long')
    if (longRun) {
      expect(longRun.durationMin).toBeLessThan(90)
    }
  })
})
