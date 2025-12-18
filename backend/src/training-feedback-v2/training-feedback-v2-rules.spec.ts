import {
  determineCharacter,
  analyzeHrStability,
  analyzeEconomy,
  calculateLoadImpact,
  calculateCoachSignals,
  calculateMetrics,
} from './training-feedback-v2-rules'
import type { IntensityBuckets } from '../types/metrics.types'
import type { WorkoutSummary } from '../types/workout.types'
import type { Trackpoint } from '../types/tcx.types'

describe('TrainingFeedbackV2Rules', () => {
  describe('determineCharacter', () => {
    it('returns regeneracja for very short duration', () => {
      const intensity: IntensityBuckets = { z1Sec: 300, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0 }
      expect(determineCharacter(intensity, 300)).toBe('regeneracja')
    })

    it('returns interwał for high z5 percentage', () => {
      const intensity: IntensityBuckets = { z1Sec: 100, z2Sec: 100, z3Sec: 100, z4Sec: 100, z5Sec: 300 }
      expect(determineCharacter(intensity, 1800)).toBe('interwał')
    })

    it('returns interwał for high z4+z5 percentage', () => {
      const intensity: IntensityBuckets = { z1Sec: 100, z2Sec: 100, z3Sec: 100, z4Sec: 200, z5Sec: 200 }
      expect(determineCharacter(intensity, 1800)).toBe('interwał')
    })

    it('returns tempo for high z3 or z4 percentage', () => {
      const intensity: IntensityBuckets = { z1Sec: 100, z2Sec: 100, z3Sec: 400, z4Sec: 100, z5Sec: 0 }
      expect(determineCharacter(intensity, 1800)).toBe('tempo')
    })

    it('returns easy for high z1 percentage', () => {
      const intensity: IntensityBuckets = { z1Sec: 1200, z2Sec: 300, z3Sec: 100, z4Sec: 0, z5Sec: 0 }
      expect(determineCharacter(intensity, 1800)).toBe('easy')
    })

    it('returns easy for high z1+z2 percentage', () => {
      const intensity: IntensityBuckets = { z1Sec: 600, z2Sec: 900, z3Sec: 100, z4Sec: 0, z5Sec: 0 }
      expect(determineCharacter(intensity, 1800)).toBe('easy')
    })

    it('returns easy as default', () => {
      const intensity: IntensityBuckets = { z1Sec: 450, z2Sec: 450, z3Sec: 450, z4Sec: 150, z5Sec: 0 }
      expect(determineCharacter(intensity, 1800)).toBe('easy')
    })

    it('handles null intensity', () => {
      expect(determineCharacter(null, 300)).toBe('regeneracja')
      expect(determineCharacter(null, 1800)).toBe('easy')
    })

    it('handles zero total intensity', () => {
      const intensity: IntensityBuckets = { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0 }
      expect(determineCharacter(intensity, 300)).toBe('regeneracja')
      expect(determineCharacter(intensity, 1800)).toBe('easy')
    })
  })

  describe('analyzeHrStability', () => {
    it('returns null drift and no artefacts for empty trackpoints', () => {
      const result = analyzeHrStability([])
      expect(result.drift).toBeNull()
      expect(result.artefacts).toBe(false)
    })

    it('returns null drift and no artefacts for single trackpoint', () => {
      const trackpoints: Trackpoint[] = [{ time: '2025-01-01T10:00:00Z', heartRateBpm: 150 }]
      const result = analyzeHrStability(trackpoints)
      expect(result.drift).toBeNull()
      expect(result.artefacts).toBe(false)
    })

    it('detects artefacts for large HR jumps', () => {
      const trackpoints: Trackpoint[] = [
        { time: '2025-01-01T10:00:00Z', heartRateBpm: 150 },
        { time: '2025-01-01T10:00:05Z', heartRateBpm: 185 }, // jump > 30
      ]
      const result = analyzeHrStability(trackpoints)
      expect(result.artefacts).toBe(true)
    })

    it('does not detect artefacts for small HR changes', () => {
      const trackpoints: Trackpoint[] = [
        { time: '2025-01-01T10:00:00Z', heartRateBpm: 150 },
        { time: '2025-01-01T10:00:05Z', heartRateBpm: 170 }, // jump < 30
      ]
      const result = analyzeHrStability(trackpoints)
      expect(result.artefacts).toBe(false)
    })

    it('calculates positive drift for increasing HR', () => {
      const trackpoints: Trackpoint[] = [
        { time: '2025-01-01T10:00:00Z', heartRateBpm: 150 },
        { time: '2025-01-01T10:05:00Z', heartRateBpm: 160 }, // +10 bpm in 5 min = +2 bpm/min
      ]
      const result = analyzeHrStability(trackpoints)
      expect(result.drift).toBeGreaterThan(0)
    })

    it('calculates negative drift for decreasing HR', () => {
      const trackpoints: Trackpoint[] = [
        { time: '2025-01-01T10:00:00Z', heartRateBpm: 160 },
        { time: '2025-01-01T10:05:00Z', heartRateBpm: 150 }, // -10 bpm in 5 min = -2 bpm/min
      ]
      const result = analyzeHrStability(trackpoints)
      expect(result.drift).toBeLessThan(0)
    })
  })

  describe('analyzeEconomy', () => {
    it('returns zero values for empty trackpoints', () => {
      const result = analyzeEconomy([])
      expect(result.paceEquality).toBe(0)
      expect(result.variance).toBe(0)
    })

    it('filters out stops and GPS noise', () => {
      const trackpoints: Trackpoint[] = [
        { time: '2025-01-01T10:00:00Z', distanceMeters: 0 },
        { time: '2025-01-01T10:00:05Z', distanceMeters: 20 }, // valid
        { time: '2025-01-01T10:00:35Z', distanceMeters: 20 }, // stop (dt > 30s)
        { time: '2025-01-01T10:00:40Z', distanceMeters: 15 }, // GPS noise (dd <= 0)
        { time: '2025-01-01T10:00:45Z', distanceMeters: 40 }, // valid
      ]
      const result = analyzeEconomy(trackpoints)
      expect(result.paceEquality).toBeGreaterThanOrEqual(0)
      expect(result.variance).toBeGreaterThanOrEqual(0)
    })

    it('calculates pace equality correctly for consistent pace', () => {
      // Consistent pace: 5 min/km = 300 sec/km
      const trackpoints: Trackpoint[] = []
      for (let i = 0; i < 10; i++) {
        const time = `2025-01-01T10:00:${String(i * 5).padStart(2, '0')}Z`
        const distanceMeters = i * 100 // 100m every 5s = 300 sec/km
        trackpoints.push({ time, distanceMeters })
      }
      const result = analyzeEconomy(trackpoints)
      expect(result.paceEquality).toBeGreaterThan(0.8) // high equality
    })
  })

  describe('calculateLoadImpact', () => {
    it('uses summary.intensity as number for weeklyLoadContribution', () => {
      const summary: WorkoutSummary = {
        intensity: 50 as any, // number (cast to any to allow number type)
        totalPoints: 100,
        selectedPoints: 100,
      }
      const result = calculateLoadImpact(summary)
      expect(result.weeklyLoadContribution).toBe(50)
    })

    it('returns zero for missing intensity', () => {
      const summary: WorkoutSummary = {
        totalPoints: 100,
        selectedPoints: 100,
      }
      const result = calculateLoadImpact(summary)
      expect(result.weeklyLoadContribution).toBe(0)
    })

    it('calculates intensityScore from buckets', () => {
      const summary: WorkoutSummary = {
        intensity: { z1Sec: 60, z2Sec: 60, z3Sec: 60, z4Sec: 60, z5Sec: 60 },
        totalPoints: 100,
        selectedPoints: 100,
      }
      const result = calculateLoadImpact(summary)
      // z1*1 + z2*2 + z3*3 + z4*4 + z5*5 = 60*1 + 60*2 + 60*3 + 60*4 + 60*5 = 900
      expect(result.intensityScore).toBe(900)
    })
  })

  describe('calculateCoachSignals', () => {
    it('calculates hrStable correctly', () => {
      const feedback = {
        character: 'easy' as const,
        hrStability: { drift: 1, artefacts: false },
        economy: { paceEquality: 0.7, variance: 20 },
        loadImpact: { weeklyLoadContribution: 20, intensityScore: 300 },
      }
      const result = calculateCoachSignals(feedback)
      expect(result.hrStable).toBe(true)
      expect(result.character).toBe('easy')
    })

    it('returns hrStable false for HR drift > 2', () => {
      const feedback = {
        character: 'easy' as const,
        hrStability: { drift: 3, artefacts: false },
        economy: { paceEquality: 0.7, variance: 20 },
        loadImpact: { weeklyLoadContribution: 20, intensityScore: 300 },
      }
      const result = calculateCoachSignals(feedback)
      expect(result.hrStable).toBe(false)
    })

    it('returns hrStable false for HR artefacts', () => {
      const feedback = {
        character: 'easy' as const,
        hrStability: { drift: null, artefacts: true },
        economy: { paceEquality: 0.7, variance: 20 },
        loadImpact: { weeklyLoadContribution: 20, intensityScore: 300 },
      }
      const result = calculateCoachSignals(feedback)
      expect(result.hrStable).toBe(false)
    })

    it('returns hrStable true for null drift and no artefacts', () => {
      const feedback = {
        character: 'easy' as const,
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.7, variance: 20 },
        loadImpact: { weeklyLoadContribution: 20, intensityScore: 300 },
      }
      const result = calculateCoachSignals(feedback)
      expect(result.hrStable).toBe(true)
    })

    it('calculates economyGood correctly', () => {
      const feedback = {
        character: 'easy' as const,
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.9, variance: 10 },
        loadImpact: { weeklyLoadContribution: 20, intensityScore: 300 },
      }
      const result = calculateCoachSignals(feedback)
      expect(result.economyGood).toBe(true)
    })

    it('returns economyGood false for paceEquality <= 0.8', () => {
      const feedback = {
        character: 'easy' as const,
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.7, variance: 20 },
        loadImpact: { weeklyLoadContribution: 20, intensityScore: 300 },
      }
      const result = calculateCoachSignals(feedback)
      expect(result.economyGood).toBe(false)
    })

    it('calculates loadHeavy correctly', () => {
      const feedback = {
        character: 'interwał' as const,
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.7, variance: 20 },
        loadImpact: { weeklyLoadContribution: 60, intensityScore: 800 },
      }
      const result = calculateCoachSignals(feedback)
      expect(result.loadHeavy).toBe(true)
    })

    it('returns loadHeavy false for weeklyLoadContribution <= 50', () => {
      const feedback = {
        character: 'easy' as const,
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.7, variance: 20 },
        loadImpact: { weeklyLoadContribution: 30, intensityScore: 500 },
      }
      const result = calculateCoachSignals(feedback)
      expect(result.loadHeavy).toBe(false)
    })

    it('preserves character', () => {
      const feedback = {
        character: 'tempo' as const,
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.7, variance: 20 },
        loadImpact: { weeklyLoadContribution: 30, intensityScore: 500 },
      }
      const result = calculateCoachSignals(feedback)
      expect(result.character).toBe('tempo')
    })
  })

  describe('calculateMetrics', () => {
    it('extracts hrDrift from hrStability', () => {
      const feedback = {
        hrStability: { drift: 2.5, artefacts: false },
        economy: { paceEquality: 0.9, variance: 10 },
        loadImpact: { weeklyLoadContribution: 30, intensityScore: 500 },
      }
      const result = calculateMetrics(feedback)
      expect(result.hrDrift).toBe(2.5)
    })

    it('handles null hrDrift', () => {
      const feedback = {
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.9, variance: 10 },
        loadImpact: { weeklyLoadContribution: 30, intensityScore: 500 },
      }
      const result = calculateMetrics(feedback)
      expect(result.hrDrift).toBeNull()
    })

    it('extracts paceEquality from economy', () => {
      const feedback = {
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.75, variance: 20 },
        loadImpact: { weeklyLoadContribution: 30, intensityScore: 500 },
      }
      const result = calculateMetrics(feedback)
      expect(result.paceEquality).toBe(0.75)
    })

    it('extracts weeklyLoadContribution from loadImpact', () => {
      const feedback = {
        hrStability: { drift: null, artefacts: false },
        economy: { paceEquality: 0.9, variance: 10 },
        loadImpact: { weeklyLoadContribution: 60, intensityScore: 800 },
      }
      const result = calculateMetrics(feedback)
      expect(result.weeklyLoadContribution).toBe(60)
    })

    it('extracts all metrics correctly', () => {
      const feedback = {
        hrStability: { drift: -1.5, artefacts: true },
        economy: { paceEquality: 0.6, variance: 30 },
        loadImpact: { weeklyLoadContribution: 45, intensityScore: 600 },
      }
      const result = calculateMetrics(feedback)
      expect(result).toEqual({
        hrDrift: -1.5,
        paceEquality: 0.6,
        weeklyLoadContribution: 45,
      })
    })
  })
})

