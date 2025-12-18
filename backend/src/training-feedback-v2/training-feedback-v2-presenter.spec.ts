import { presentFeedback } from './training-feedback-v2-presenter'
import type { TrainingFeedbackV2 } from './training-feedback-v2.types'

describe('TrainingFeedbackV2Presenter', () => {
  describe('presentFeedback', () => {
    it('generates conclusion for easy character', () => {
      const feedback: TrainingFeedbackV2 = {
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
        workoutId: 1,
      }
      const createdAt = new Date('2025-01-01T10:00:00Z')
      const result = presentFeedback(feedback, createdAt)
      expect(result.coachConclusion).toContain('Trening łatwy')
      expect(result.coachConclusion).toContain('stabilne tempo')
    })

    it('includes HR artefacts in conclusion', () => {
      const feedback: TrainingFeedbackV2 = {
        character: 'easy',
        hrStability: { drift: null, artefacts: true },
        economy: { paceEquality: 0.9, variance: 10 },
        loadImpact: { weeklyLoadContribution: 30, intensityScore: 500 },
        coachSignals: {
          character: 'easy',
          hrStable: false,
          economyGood: true,
          loadHeavy: false,
        },
        metrics: {
          hrDrift: null,
          paceEquality: 0.9,
          weeklyLoadContribution: 30,
        },
        workoutId: 1,
      }
      const createdAt = new Date('2025-01-01T10:00:00Z')
      const result = presentFeedback(feedback, createdAt)
      expect(result.coachConclusion).toContain('wykryto artefakty tętna')
    })

    it('includes stable pace in conclusion', () => {
      const feedback: TrainingFeedbackV2 = {
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
        workoutId: 1,
      }
      const createdAt = new Date('2025-01-01T10:00:00Z')
      const result = presentFeedback(feedback, createdAt)
      expect(result.coachConclusion).toContain('stabilne tempo')
    })

    it('includes variable pace in conclusion', () => {
      const feedback: TrainingFeedbackV2 = {
        character: 'tempo',
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.3, variance: 100 },
        loadImpact: { weeklyLoadContribution: 30, intensityScore: 500 },
        coachSignals: {
          character: 'tempo',
          hrStable: true,
          economyGood: false,
          loadHeavy: false,
        },
        metrics: {
          hrDrift: null,
          paceEquality: 0.3,
          weeklyLoadContribution: 30,
        },
        workoutId: 1,
      }
      const createdAt = new Date('2025-01-01T10:00:00Z')
      const result = presentFeedback(feedback, createdAt)
      expect(result.coachConclusion).toContain('zmienne tempo')
    })

    it('includes high load impact in conclusion', () => {
      const feedback: TrainingFeedbackV2 = {
        character: 'interwał',
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.7, variance: 50 },
        loadImpact: { weeklyLoadContribution: 60, intensityScore: 800 },
        coachSignals: {
          character: 'interwał',
          hrStable: true,
          economyGood: false,
          loadHeavy: true,
        },
        metrics: {
          hrDrift: null,
          paceEquality: 0.7,
          weeklyLoadContribution: 60,
        },
        workoutId: 1,
      }
      const createdAt = new Date('2025-01-01T10:00:00Z')
      const result = presentFeedback(feedback, createdAt)
      expect(result.coachConclusion).toContain('wysoki wkład w obciążenie tygodniowe')
    })

    it('includes low load impact in conclusion', () => {
      const feedback: TrainingFeedbackV2 = {
        character: 'regeneracja',
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.6, variance: 30 },
        loadImpact: { weeklyLoadContribution: 5, intensityScore: 100 },
        coachSignals: {
          character: 'regeneracja',
          hrStable: true,
          economyGood: false,
          loadHeavy: false,
        },
        metrics: {
          hrDrift: null,
          paceEquality: 0.6,
          weeklyLoadContribution: 5,
        },
        workoutId: 1,
      }
      const createdAt = new Date('2025-01-01T10:00:00Z')
      const result = presentFeedback(feedback, createdAt)
      expect(result.coachConclusion).toContain('niski wkład w obciążenie tygodniowe')
    })

    it('includes HR drift increase in conclusion', () => {
      const feedback: TrainingFeedbackV2 = {
        character: 'easy',
        hrStability: { drift: 3, artefacts: false },
        economy: { paceEquality: 0.7, variance: 20 },
        loadImpact: { weeklyLoadContribution: 20, intensityScore: 300 },
        coachSignals: {
          character: 'easy',
          hrStable: false,
          economyGood: false,
          loadHeavy: false,
        },
        metrics: {
          hrDrift: 3,
          paceEquality: 0.7,
          weeklyLoadContribution: 20,
        },
        workoutId: 1,
      }
      const createdAt = new Date('2025-01-01T10:00:00Z')
      const result = presentFeedback(feedback, createdAt)
      expect(result.coachConclusion).toContain('wzrost tętna w czasie')
    })

    it('includes HR drift decrease in conclusion', () => {
      const feedback: TrainingFeedbackV2 = {
        character: 'easy',
        hrStability: { drift: -3, artefacts: false },
        economy: { paceEquality: 0.7, variance: 20 },
        loadImpact: { weeklyLoadContribution: 20, intensityScore: 300 },
        coachSignals: {
          character: 'easy',
          hrStable: false,
          economyGood: false,
          loadHeavy: false,
        },
        metrics: {
          hrDrift: 3,
          paceEquality: 0.7,
          weeklyLoadContribution: 20,
        },
        workoutId: 1,
      }
      const createdAt = new Date('2025-01-01T10:00:00Z')
      const result = presentFeedback(feedback, createdAt)
      expect(result.coachConclusion).toContain('spadek tętna w czasie')
    })

    it('returns default message for empty conditions', () => {
      const feedback: TrainingFeedbackV2 = {
        character: 'regeneracja',
        hrStability: { drift: 0, artefacts: false },
        economy: { paceEquality: 0.6, variance: 30 },
        loadImpact: { weeklyLoadContribution: 20, intensityScore: 200 },
        coachSignals: {
          character: 'regeneracja',
          hrStable: true,
          economyGood: false,
          loadHeavy: false,
        },
        metrics: {
          hrDrift: 0,
          paceEquality: 0.6,
          weeklyLoadContribution: 20,
        },
        workoutId: 1,
      }
      const createdAt = new Date('2025-01-01T10:00:00Z')
      const result = presentFeedback(feedback, createdAt)
      expect(result.coachConclusion).toBeTruthy()
      expect(result.coachConclusion.length).toBeGreaterThan(0)
    })

    it('preserves all feedback fields', () => {
      const feedback: TrainingFeedbackV2 = {
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
        workoutId: 1,
      }
      const createdAt = new Date('2025-01-01T10:00:00Z')
      const result = presentFeedback(feedback, createdAt)
      expect(result.character).toBe(feedback.character)
      expect(result.hrStability).toEqual(feedback.hrStability)
      expect(result.economy).toEqual(feedback.economy)
      expect(result.loadImpact).toEqual(feedback.loadImpact)
      expect(result.coachSignals).toEqual(feedback.coachSignals)
      expect(result.metrics).toEqual(feedback.metrics)
      expect(result.generatedAtIso).toBe('2025-01-01T10:00:00.000Z')
      expect(result.workoutId).toBe(feedback.workoutId)
    })

    it('generates generatedAtIso from createdAt Date', () => {
      const feedback: TrainingFeedbackV2 = {
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
        workoutId: 1,
      }
      const createdAt = new Date('2025-01-15T14:30:00Z')
      const result = presentFeedback(feedback, createdAt)
      expect(result.generatedAtIso).toBe('2025-01-15T14:30:00.000Z')
    })

    it('generates generatedAtIso from createdAt string', () => {
      const feedback: TrainingFeedbackV2 = {
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
        workoutId: 1,
      }
      const createdAt = '2025-01-20T08:15:00Z'
      const result = presentFeedback(feedback, createdAt)
      expect(result.generatedAtIso).toBe('2025-01-20T08:15:00.000Z')
    })

    it('uses Date.now() as fallback when createdAt is not provided', () => {
      const feedback: TrainingFeedbackV2 = {
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
        workoutId: 1,
      }
      const before = new Date().toISOString()
      const result = presentFeedback(feedback)
      const after = new Date().toISOString()
      expect(result.generatedAtIso).toBeTruthy()
      expect(result.generatedAtIso >= before).toBe(true)
      expect(result.generatedAtIso <= after).toBe(true)
    })
  })
})

