import { WeeklyPlanService } from '../src/weekly-plan/weekly-plan.service'
import type { TrainingContext } from '../src/training-context/training-context.types'
import { weeklyPlanSchema } from '../src/weekly-plan/weekly-plan.schema'

describe('WeeklyPlanService', () => {
  const service = new WeeklyPlanService()

  const createMockContext = (overrides?: Partial<TrainingContext>): TrainingContext => {
    return {
      generatedAtIso: '2024-01-15T12:00:00.000Z', // Monday
      windowDays: 28,
      signals: {
        period: { from: '2024-01-01T00:00:00.000Z', to: '2024-01-15T12:00:00.000Z' },
        volume: { distanceKm: 100, durationMin: 400, sessions: 4 },
        intensity: { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0, totalSec: 0 },
        longRun: { exists: false, distanceKm: 0, durationMin: 0, workoutId: null, workoutDt: null },
        load: { weeklyLoad: 0, rolling4wLoad: 0 },
        consistency: { sessionsPerWeek: 0, streakWeeks: 0 },
        flags: { injuryRisk: false, fatigue: false },
      },
      profile: {
        timezone: 'Europe/Warsaw',
        runningDays: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
        surfaces: { preferTrail: false, avoidAsphalt: false },
        shoes: { avoidZeroDrop: false },
      },
      ...overrides,
    }
  }

  it('generates exactly 7 sessions, unique days, ordered mon..sun', () => {
    const ctx = createMockContext()
    const plan = service.generatePlan(ctx)

    expect(plan.sessions).toHaveLength(7)
    const days = plan.sessions.map((s) => s.day)
    expect(new Set(days).size).toBe(7)
    expect(days).toEqual(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])

    // Schema validation
    const parsed = weeklyPlanSchema.safeParse(plan)
    expect(parsed.success).toBe(true)
  })

  it('generatedAtIso === ctx.generatedAtIso', () => {
    const ctx = createMockContext({ generatedAtIso: '2024-02-20T15:30:00.000Z' })
    const plan = service.generatePlan(ctx)

    expect(plan.generatedAtIso).toBe(ctx.generatedAtIso)
  })

  it('inputsHash is 64-char hex string', () => {
    const ctx = createMockContext()
    const plan = service.generatePlan(ctx)

    expect(plan.inputsHash).toHaveLength(64)
    expect(plan.inputsHash).toMatch(/^[0-9a-f]{64}$/)
  })

  it('is deterministic: same ctx → identical plan', () => {
    const ctx = createMockContext()
    const plan1 = service.generatePlan(ctx)
    const plan2 = service.generatePlan(ctx)

    expect(plan1).toEqual(plan2)
    expect(plan1.inputsHash).toBe(plan2.inputsHash)
  })

  it('calculates week boundaries correctly (Monday 00:00Z to Sunday 23:59:59.999Z)', () => {
    // Test with Monday
    const ctxMonday = createMockContext({ generatedAtIso: '2024-01-15T12:00:00.000Z' }) // Monday
    const planMonday = service.generatePlan(ctxMonday)

    const weekStart = new Date(planMonday.weekStartIso)
    const weekEnd = new Date(planMonday.weekEndIso)

    expect(weekStart.getUTCDay()).toBe(1) // Monday
    expect(weekStart.getUTCHours()).toBe(0)
    expect(weekStart.getUTCMinutes()).toBe(0)
    expect(weekStart.getUTCSeconds()).toBe(0)
    expect(weekStart.getUTCMilliseconds()).toBe(0)

    expect(weekEnd.getUTCDay()).toBe(0) // Sunday
    expect(weekEnd.getUTCHours()).toBe(23)
    expect(weekEnd.getUTCMinutes()).toBe(59)
    expect(weekEnd.getUTCSeconds()).toBe(59)
    expect(weekEnd.getUTCMilliseconds()).toBe(999)

    // Test with Sunday
    const ctxSunday = createMockContext({ generatedAtIso: '2024-01-21T18:00:00.000Z' }) // Sunday
    const planSunday = service.generatePlan(ctxSunday)

    const weekStartSun = new Date(planSunday.weekStartIso)
    expect(weekStartSun.getUTCDay()).toBe(1) // Should still be Monday
    expect(weekStartSun.getTime()).toBe(weekStart.getTime()) // Same week
  })

  it('places long run correctly based on profile.runningDays', () => {
    // Prefer sun
    const ctxSun = createMockContext({
      profile: {
        timezone: 'Europe/Warsaw',
        runningDays: ['mon', 'wed', 'fri', 'sun'],
        surfaces: { preferTrail: false, avoidAsphalt: false },
        shoes: { avoidZeroDrop: false },
      },
    })
    const planSun = service.generatePlan(ctxSun)
    const longRunSun = planSun.sessions.find((s) => s.type === 'long')
    expect(longRunSun?.day).toBe('sun')

    // Prefer sat if no sun
    const ctxSat = createMockContext({
      profile: {
        timezone: 'Europe/Warsaw',
        runningDays: ['mon', 'wed', 'sat'],
        surfaces: { preferTrail: false, avoidAsphalt: false },
        shoes: { avoidZeroDrop: false },
      },
    })
    const planSat = service.generatePlan(ctxSat)
    const longRunSat = planSat.sessions.find((s) => s.type === 'long')
    expect(longRunSat?.day).toBe('sat')
  })

  it('includes quality session only when conditions met', () => {
    // Conditions met: sessions >= 3 and no fatigue
    const ctxWithQuality = createMockContext({
      signals: {
        period: { from: '2024-01-01T00:00:00.000Z', to: '2024-01-15T12:00:00.000Z' },
        volume: { distanceKm: 100, durationMin: 400, sessions: 4 },
        intensity: { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0, totalSec: 0 },
        longRun: { exists: false, distanceKm: 0, durationMin: 0, workoutId: null, workoutDt: null },
        load: { weeklyLoad: 0, rolling4wLoad: 0 },
        consistency: { sessionsPerWeek: 0, streakWeeks: 0 },
        flags: { injuryRisk: false, fatigue: false },
      },
      profile: {
        timezone: 'Europe/Warsaw',
        runningDays: ['mon', 'tue', 'wed', 'thu', 'fri'],
        surfaces: { preferTrail: false, avoidAsphalt: false },
        shoes: { avoidZeroDrop: false },
      },
    })
    const planWithQuality = service.generatePlan(ctxWithQuality)
    expect(planWithQuality.summary.qualitySessions).toBe(1)
    expect(planWithQuality.sessions.some((s) => s.type === 'quality')).toBe(true)

    // Conditions not met: sessions < 3
    const ctxNoQuality1 = createMockContext({
      signals: {
        period: { from: '2024-01-01T00:00:00.000Z', to: '2024-01-15T12:00:00.000Z' },
        volume: { distanceKm: 50, durationMin: 200, sessions: 2 },
        intensity: { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0, totalSec: 0 },
        longRun: { exists: false, distanceKm: 0, durationMin: 0, workoutId: null, workoutDt: null },
        load: { weeklyLoad: 0, rolling4wLoad: 0 },
        consistency: { sessionsPerWeek: 0, streakWeeks: 0 },
        flags: { injuryRisk: false, fatigue: false },
      },
    })
    const planNoQuality1 = service.generatePlan(ctxNoQuality1)
    expect(planNoQuality1.summary.qualitySessions).toBe(0)
    expect(planNoQuality1.sessions.some((s) => s.type === 'quality')).toBe(false)

    // Conditions not met: fatigue = true
    const ctxNoQuality2 = createMockContext({
      signals: {
        period: { from: '2024-01-01T00:00:00.000Z', to: '2024-01-15T12:00:00.000Z' },
        volume: { distanceKm: 100, durationMin: 400, sessions: 4 },
        intensity: { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0, totalSec: 0 },
        longRun: { exists: false, distanceKm: 0, durationMin: 0, workoutId: null, workoutDt: null },
        load: { weeklyLoad: 0, rolling4wLoad: 0 },
        consistency: { sessionsPerWeek: 0, streakWeeks: 0 },
        flags: { injuryRisk: false, fatigue: true },
      },
    })
    const planNoQuality2 = service.generatePlan(ctxNoQuality2)
    expect(planNoQuality2.summary.qualitySessions).toBe(0)
    expect(planNoQuality2.sessions.some((s) => s.type === 'quality')).toBe(false)
  })

  it('fatigue flag reduces durations and removes quality', () => {
    const ctxFatigue = createMockContext({
      signals: {
        period: { from: '2024-01-01T00:00:00.000Z', to: '2024-01-15T12:00:00.000Z' },
        volume: { distanceKm: 100, durationMin: 400, sessions: 4 },
        intensity: { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0, totalSec: 0 },
        longRun: { exists: false, distanceKm: 0, durationMin: 0, workoutId: null, workoutDt: null },
        load: { weeklyLoad: 0, rolling4wLoad: 0 },
        consistency: { sessionsPerWeek: 0, streakWeeks: 0 },
        flags: { injuryRisk: false, fatigue: true },
      },
    })
    const planFatigue = service.generatePlan(ctxFatigue)

    const longRun = planFatigue.sessions.find((s) => s.type === 'long')
    expect(longRun?.durationMin).toBe(75) // 70-80 range, using 75

    const easySessions = planFatigue.sessions.filter((s) => s.type === 'easy')
    easySessions.forEach((s) => {
      expect(s.durationMin).toBe(35) // 30-40 range, using 35
    })

    expect(planFatigue.summary.qualitySessions).toBe(0)
  })

  it('applies surface hints correctly', () => {
    // preferTrail → long run gets trail
    const ctxTrail = createMockContext({
      profile: {
        timezone: 'Europe/Warsaw',
        runningDays: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
        surfaces: { preferTrail: true, avoidAsphalt: false },
        shoes: { avoidZeroDrop: false },
      },
    })
    const planTrail = service.generatePlan(ctxTrail)
    const longRunTrail = planTrail.sessions.find((s) => s.type === 'long')
    expect(longRunTrail?.surfaceHint).toBe('trail')

    // avoidAsphalt → weekdays get track, weekend gets trail
    const ctxAvoidAsphalt = createMockContext({
      profile: {
        timezone: 'Europe/Warsaw',
        runningDays: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
        surfaces: { preferTrail: false, avoidAsphalt: true },
        shoes: { avoidZeroDrop: false },
      },
    })
    const planAvoidAsphalt = service.generatePlan(ctxAvoidAsphalt)
    const longRunAvoid = planAvoidAsphalt.sessions.find((s) => s.type === 'long')
    const isWeekend = longRunAvoid?.day === 'sat' || longRunAvoid?.day === 'sun'
    expect(longRunAvoid?.surfaceHint).toBe(isWeekend ? 'trail' : 'track')

    const weekdayEasy = planAvoidAsphalt.sessions.find(
      (s) => s.type === 'easy' && s.day !== 'sat' && s.day !== 'sun',
    )
    expect(weekdayEasy?.surfaceHint).toBe('track')
  })

  it('calculates summary correctly', () => {
    const ctx = createMockContext()
    const plan = service.generatePlan(ctx)

    const actualTotalDuration = plan.sessions.reduce((sum, s) => sum + s.durationMin, 0)
    expect(plan.summary.totalDurationMin).toBe(actualTotalDuration)

    const actualQualitySessions = plan.sessions.filter((s) => s.type === 'quality').length
    expect(plan.summary.qualitySessions).toBe(actualQualitySessions)

    const actualLongRunDay = plan.sessions.find((s) => s.type === 'long')?.day
    expect(plan.summary.longRunDay).toBe(actualLongRunDay)
  })

  it('includes strides when ≥3 running days', () => {
    const ctxWithStrides = createMockContext({
      profile: {
        timezone: 'Europe/Warsaw',
        runningDays: ['mon', 'tue', 'wed', 'thu'],
        surfaces: { preferTrail: false, avoidAsphalt: false },
        shoes: { avoidZeroDrop: false },
      },
    })
    const planWithStrides = service.generatePlan(ctxWithStrides)
    const sessionsWithStrides = planWithStrides.sessions.filter((s) => s.notes?.some((note) => note.includes('strides')))
    expect(sessionsWithStrides.length).toBe(1) // Only one easy session should have strides

    const ctxNoStrides = createMockContext({
      profile: {
        timezone: 'Europe/Warsaw',
        runningDays: ['mon', 'tue'], // Only 2 days
        surfaces: { preferTrail: false, avoidAsphalt: false },
        shoes: { avoidZeroDrop: false },
      },
    })
    const planNoStrides = service.generatePlan(ctxNoStrides)
    const sessionsWithStridesNo = planNoStrides.sessions.filter((s) => s.notes?.some((note) => note.includes('strides')))
    expect(sessionsWithStridesNo.length).toBe(0)
  })

  it('windowDays is passthrough from context', () => {
    const ctx = createMockContext({ windowDays: 14 })
    const plan = service.generatePlan(ctx)
    expect(plan.windowDays).toBe(14)
  })
})

