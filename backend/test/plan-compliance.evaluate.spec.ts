import { evaluatePlanCompliance } from '../src/training-plan/plan-compliance.evaluate'
import type { PlanSnapshotDay } from '../src/plan-snapshot/plan-snapshot.types'

describe('evaluatePlanCompliance', () => {
  describe('plannedMissing', () => {
    it('returns MAJOR_DEVIATION when planned is null', () => {
      const result = evaluatePlanCompliance(null, { durationMin: 30 })
      expect(result.plannedMissing).toBe(true)
      expect(result.status).toBe('MAJOR_DEVIATION')
    })
  })

  describe('rest + actual', () => {
    it('returns unplannedSession MAJOR when rest day has workout', () => {
      const planned: PlanSnapshotDay = {
        dateKey: '2025-01-01',
        type: 'rest',
      }
      const result = evaluatePlanCompliance(planned, { durationMin: 30 })
      expect(result.unplannedSession).toBe(true)
      expect(result.status).toBe('MAJOR_DEVIATION')
    })
  })

  describe('non-rest + no actual', () => {
    it('returns skippedPlannedSession MAJOR when planned workout is missing', () => {
      const planned: PlanSnapshotDay = {
        dateKey: '2025-01-01',
        type: 'easy',
        plannedDurationMin: 30,
      }
      const result = evaluatePlanCompliance(planned, null)
      expect(result.skippedPlannedSession).toBe(true)
      expect(result.status).toBe('MAJOR_DEVIATION')
    })
  })

  describe('duration thresholds', () => {
    const planned: PlanSnapshotDay = {
      dateKey: '2025-01-01',
      type: 'easy',
      plannedDurationMin: 60,
    }

    it('returns MAJOR_DEVIATION for undershoot < 70%', () => {
      const result = evaluatePlanCompliance(planned, { durationMin: 40 }) // 40/60 = 0.67
      expect(result.undershootDuration).toBe(true)
      expect(result.status).toBe('MAJOR_DEVIATION')
      expect(result.durationRatio).toBeCloseTo(0.67, 2)
    })

    it('returns MINOR_DEVIATION for undershoot 70-85%', () => {
      const result = evaluatePlanCompliance(planned, { durationMin: 45 }) // 45/60 = 0.75
      expect(result.undershootDuration).toBe(true)
      expect(result.status).toBe('MINOR_DEVIATION')
      expect(result.durationRatio).toBeCloseTo(0.75, 2)
    })

    it('returns OK for perfect execution (85-115%)', () => {
      const result = evaluatePlanCompliance(planned, { durationMin: 60 }) // 60/60 = 1.0
      expect(result.status).toBe('OK')
      expect(result.durationRatio).toBeCloseTo(1.0, 2)
      expect(result.undershootDuration).toBeUndefined()
      expect(result.overshootDuration).toBeUndefined()
    })

    it('returns MINOR_DEVIATION for overshoot 115-130%', () => {
      const result = evaluatePlanCompliance(planned, { durationMin: 72 }) // 72/60 = 1.2
      expect(result.overshootDuration).toBe(true)
      expect(result.status).toBe('MINOR_DEVIATION')
      expect(result.durationRatio).toBeCloseTo(1.2, 2)
    })

    it('returns MAJOR_DEVIATION for overshoot > 130%', () => {
      const result = evaluatePlanCompliance(planned, { durationMin: 80 }) // 80/60 = 1.33
      expect(result.overshootDuration).toBe(true)
      expect(result.status).toBe('MAJOR_DEVIATION')
      expect(result.durationRatio).toBeCloseTo(1.33, 2)
    })
  })

  describe('distance thresholds', () => {
    const planned: PlanSnapshotDay = {
      dateKey: '2025-01-01',
      type: 'long',
      plannedDurationMin: 60,
      plannedDistanceKm: 10,
    }

    it('returns MAJOR_DEVIATION for distance undershoot < 70%', () => {
      const result = evaluatePlanCompliance(planned, {
        durationMin: 60,
        distanceKm: 6,
      }) // 6/10 = 0.6
      expect(result.undershootDistance).toBe(true)
      expect(result.status).toBe('MAJOR_DEVIATION')
      expect(result.distanceRatio).toBeCloseTo(0.6, 2)
    })

    it('returns MINOR_DEVIATION for distance overshoot 115-130%', () => {
      const result = evaluatePlanCompliance(planned, {
        durationMin: 60,
        distanceKm: 12,
      }) // 12/10 = 1.2
      expect(result.overshootDistance).toBe(true)
      expect(result.status).toBe('MINOR_DEVIATION')
      expect(result.distanceRatio).toBeCloseTo(1.2, 2)
    })

    it('returns OK when both duration and distance are within range', () => {
      const result = evaluatePlanCompliance(planned, {
        durationMin: 60,
        distanceKm: 10,
      })
      expect(result.status).toBe('OK')
      expect(result.durationRatio).toBeCloseTo(1.0, 2)
      expect(result.distanceRatio).toBeCloseTo(1.0, 2)
    })
  })
})

