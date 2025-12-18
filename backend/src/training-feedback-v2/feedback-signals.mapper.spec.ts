import { mapFeedbackToSignals } from './feedback-signals.mapper'
import type { TrainingFeedbackV2 } from './training-feedback-v2.types'

describe('mapFeedbackToSignals', () => {
  const createMockFeedback = (overrides: Partial<TrainingFeedbackV2> = {}): TrainingFeedbackV2 => ({
    character: 'easy',
    hrStability: { drift: null, artefacts: false },
    economy: { paceEquality: 0.85, variance: 0.1 },
    loadImpact: { weeklyLoadContribution: 30, intensityScore: 100 },
    coachSignals: {
      character: 'easy',
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
    ...overrides,
  })

  describe('intensityClass mapping', () => {
    it('maps easy to easy', () => {
      const feedback = createMockFeedback({ character: 'easy', coachSignals: { ...createMockFeedback().coachSignals, character: 'easy' } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.intensityClass).toBe('easy')
    })

    it('maps regeneracja to easy', () => {
      const feedback = createMockFeedback({ character: 'regeneracja', coachSignals: { ...createMockFeedback().coachSignals, character: 'regeneracja' } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.intensityClass).toBe('easy')
    })

    it('maps tempo to moderate', () => {
      const feedback = createMockFeedback({ character: 'tempo', coachSignals: { ...createMockFeedback().coachSignals, character: 'tempo' } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.intensityClass).toBe('moderate')
    })

    it('maps interwał to hard', () => {
      const feedback = createMockFeedback({ character: 'interwał', coachSignals: { ...createMockFeedback().coachSignals, character: 'interwał' } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.intensityClass).toBe('hard')
    })
  })

  describe('hrStable mapping', () => {
    it('maps hrStable directly from coachSignals', () => {
      const feedback = createMockFeedback({ coachSignals: { ...createMockFeedback().coachSignals, hrStable: true } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.hrStable).toBe(true)
    })

    it('maps hrStable false correctly', () => {
      const feedback = createMockFeedback({ coachSignals: { ...createMockFeedback().coachSignals, hrStable: false } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.hrStable).toBe(false)
    })
  })

  describe('economyFlag mapping', () => {
    it('maps paceEquality > 0.8 to good', () => {
      const feedback = createMockFeedback({ metrics: { ...createMockFeedback().metrics, paceEquality: 0.85 } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.economyFlag).toBe('good')
    })

    it('maps paceEquality > 0.6 to ok', () => {
      const feedback = createMockFeedback({ metrics: { ...createMockFeedback().metrics, paceEquality: 0.7 } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.economyFlag).toBe('ok')
    })

    it('maps paceEquality <= 0.6 to poor', () => {
      const feedback = createMockFeedback({ metrics: { ...createMockFeedback().metrics, paceEquality: 0.5 } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.economyFlag).toBe('poor')
    })
  })

  describe('loadImpact mapping', () => {
    it('maps weeklyLoadContribution > 50 to high', () => {
      const feedback = createMockFeedback({ metrics: { ...createMockFeedback().metrics, weeklyLoadContribution: 60 } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.loadImpact).toBe('high')
    })

    it('maps weeklyLoadContribution > 25 to medium', () => {
      const feedback = createMockFeedback({ metrics: { ...createMockFeedback().metrics, weeklyLoadContribution: 30 } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.loadImpact).toBe('medium')
    })

    it('maps weeklyLoadContribution <= 25 to low', () => {
      const feedback = createMockFeedback({ metrics: { ...createMockFeedback().metrics, weeklyLoadContribution: 20 } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.loadImpact).toBe('low')
    })
  })

  describe('warnings', () => {
    it('sets overloadRisk when loadImpact is high', () => {
      const feedback = createMockFeedback({ metrics: { ...createMockFeedback().metrics, weeklyLoadContribution: 60 } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.warnings.overloadRisk).toBe(true)
    })

    it('sets overloadRisk when weeklyLoadContribution > 50', () => {
      const feedback = createMockFeedback({ metrics: { ...createMockFeedback().metrics, weeklyLoadContribution: 55 } })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.warnings.overloadRisk).toBe(true)
    })

    it('sets hrInstability when hrStable is false and character is easy', () => {
      const feedback = createMockFeedback({
        character: 'easy',
        coachSignals: { ...createMockFeedback().coachSignals, hrStable: false, character: 'easy' },
      })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.warnings.hrInstability).toBe(true)
    })

    it('does not set hrInstability when hrStable is false but character is not easy', () => {
      const feedback = createMockFeedback({
        character: 'tempo',
        coachSignals: { ...createMockFeedback().coachSignals, hrStable: false, character: 'tempo' },
      })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.warnings.hrInstability).toBeUndefined()
    })

    it('sets economyDrop when economyFlag is poor and character is easy', () => {
      const feedback = createMockFeedback({
        character: 'easy',
        coachSignals: { ...createMockFeedback().coachSignals, character: 'easy' },
        metrics: { ...createMockFeedback().metrics, paceEquality: 0.5 },
      })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.warnings.economyDrop).toBe(true)
    })

    it('does not set economyDrop when economyFlag is poor but character is not easy', () => {
      const feedback = createMockFeedback({
        character: 'tempo',
        coachSignals: { ...createMockFeedback().coachSignals, character: 'tempo' },
        metrics: { ...createMockFeedback().metrics, paceEquality: 0.5 },
      })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.warnings.economyDrop).toBeUndefined()
    })

    it('does not set warnings when conditions are not met', () => {
      const feedback = createMockFeedback({
        character: 'easy',
        coachSignals: { ...createMockFeedback().coachSignals, hrStable: true, character: 'easy' },
        metrics: { ...createMockFeedback().metrics, paceEquality: 0.85, weeklyLoadContribution: 20 },
      })
      const signals = mapFeedbackToSignals(feedback)
      expect(signals.warnings.overloadRisk).toBeUndefined()
      expect(signals.warnings.hrInstability).toBeUndefined()
      expect(signals.warnings.economyDrop).toBeUndefined()
    })
  })
})
