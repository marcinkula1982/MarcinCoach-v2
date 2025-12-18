import { TrainingAdjustmentsService } from '../src/training-adjustments/training-adjustments.service'
import type { TrainingContext } from '../src/training-context/training-context.types'
import type { FeedbackSignals } from '../src/training-feedback-v2/feedback-signals.types'

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

  describe('with FeedbackSignals', () => {
    const mockFeedbackSignals = (overrides?: Partial<FeedbackSignals>): FeedbackSignals => ({
      intensityClass: 'easy',
      hrStable: true,
      economyFlag: 'good',
      loadImpact: 'low',
      warnings: {},
      ...overrides,
    })

    it('adds reduce_load adjustment when overloadRisk is true', () => {
      const context = baseContext()
      const feedbackSignals = mockFeedbackSignals({
        warnings: { overloadRisk: true },
      })

      const result = service.generate(context, feedbackSignals)

      const reduceLoad = result.adjustments.find((a) => a.code === 'reduce_load')
      expect(reduceLoad).toBeDefined()
      expect(reduceLoad?.severity).toBe('high')
      expect(reduceLoad?.params).toEqual({ reductionPct: 25 })
      expect(reduceLoad?.evidence).toEqual([{ key: 'overloadRisk', value: true }])
    })

    it('does not duplicate reduce_load if already exists from fatigue', () => {
      const context = baseContext({
        signals: {
          ...baseContext().signals,
          flags: { injuryRisk: false, fatigue: true },
        },
      })
      const feedbackSignals = mockFeedbackSignals({
        warnings: { overloadRisk: true },
      })

      const result = service.generate(context, feedbackSignals)

      const reduceLoadAdjustments = result.adjustments.filter((a) => a.code === 'reduce_load')
      expect(reduceLoadAdjustments).toHaveLength(1) // tylko jeden, nie duplikat
    })

    it('adds recovery_focus adjustment when hrInstability is true', () => {
      const context = baseContext()
      const feedbackSignals = mockFeedbackSignals({
        warnings: { hrInstability: true },
      })

      const result = service.generate(context, feedbackSignals)

      const recoveryFocus = result.adjustments.find((a) => a.code === 'recovery_focus')
      expect(recoveryFocus).toBeDefined()
      expect(recoveryFocus?.severity).toBe('high')
      expect(recoveryFocus?.params).toEqual({
        replaceHardSessionWithEasy: true,
        longRunReductionPct: 15,
      })
      expect(recoveryFocus?.evidence).toEqual([{ key: 'hrInstability', value: true }])
    })

    it('adds technique_focus adjustment when economyDrop is true', () => {
      const context = baseContext()
      const feedbackSignals = mockFeedbackSignals({
        warnings: { economyDrop: true },
      })

      const result = service.generate(context, feedbackSignals)

      const techniqueFocus = result.adjustments.find((a) => a.code === 'technique_focus')
      expect(techniqueFocus).toBeDefined()
      expect(techniqueFocus?.severity).toBe('medium')
      expect(techniqueFocus?.params).toEqual({
        addStrides: true,
        stridesCount: 6,
        stridesDurationSec: 20,
      })
      expect(techniqueFocus?.evidence).toEqual([{ key: 'economyDrop', value: true }])
    })

    it('returns only base adjustments when feedbackSignals is undefined', () => {
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

      const result = service.generate(context, undefined)

      expect(result.adjustments).toHaveLength(0)
      expect(result.adjustments.some((a) => a.code === 'recovery_focus')).toBe(false)
      expect(result.adjustments.some((a) => a.code === 'technique_focus')).toBe(false)
    })

    it('adds all three adjustments when all warnings are true', () => {
      const context = baseContext()
      const feedbackSignals = mockFeedbackSignals({
        warnings: {
          overloadRisk: true,
          hrInstability: true,
          economyDrop: true,
        },
      })

      const result = service.generate(context, feedbackSignals)

      expect(result.adjustments.some((a) => a.code === 'reduce_load' && a.params?.reductionPct === 25)).toBe(true)
      expect(
        result.adjustments.some(
          (a) =>
            a.code === 'recovery_focus' &&
            a.params?.replaceHardSessionWithEasy === true &&
            a.params?.longRunReductionPct === 15,
        ),
      ).toBe(true)
      expect(
        result.adjustments.some(
          (a) =>
            a.code === 'technique_focus' &&
            a.params?.addStrides === true &&
            a.params?.stridesCount === 6 &&
            a.params?.stridesDurationSec === 20,
        ),
      ).toBe(true)
    })
  })
})


