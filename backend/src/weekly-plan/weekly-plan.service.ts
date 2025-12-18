import { Injectable, InternalServerErrorException } from '@nestjs/common'
import { createHash } from 'crypto'
const stringify = require('fast-json-stable-stringify')
import type { TrainingContext } from '../training-context/training-context.types'
import type { TrainingAdjustments } from '../training-adjustments/training-adjustments.types'
import type { TrainingDay, PlannedSession, WeeklyPlan } from './weekly-plan.types'
import { weeklyPlanSchema } from './weekly-plan.schema'

@Injectable()
export class WeeklyPlanService {
  private readonly DAYS_ORDER: TrainingDay[] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']

  /**
   * Calculate ISO week boundaries (Monday 00:00:00.000Z to Sunday 23:59:59.999Z)
   */
  private calculateWeekBoundaries(generatedAtIso: string): { weekStartIso: string; weekEndIso: string } {
    const date = new Date(generatedAtIso)

    // Get day of week (0=Sunday, 1=Monday, ..., 6=Saturday)
    const dayOfWeek = date.getUTCDay()
    // Convert to ISO week day (0=Monday, 1=Tuesday, ..., 6=Sunday)
    const isoDayOfWeek = dayOfWeek === 0 ? 6 : dayOfWeek - 1

    // Calculate Monday 00:00:00.000Z
    const monday = new Date(date)
    monday.setUTCDate(date.getUTCDate() - isoDayOfWeek)
    monday.setUTCHours(0, 0, 0, 0)
    const weekStartIso = monday.toISOString()

    // Calculate Sunday 23:59:59.999Z
    const sunday = new Date(monday)
    sunday.setUTCDate(monday.getUTCDate() + 6)
    sunday.setUTCHours(23, 59, 59, 999)
    const weekEndIso = sunday.toISOString()

    return { weekStartIso, weekEndIso }
  }

  /**
   * Calculate SHA256 hash of stable JSON stringified TrainingContext
   */
  private calculateInputsHash(ctx: TrainingContext): string {
    const stableJson = stringify(ctx)
    const hash = createHash('sha256').update(stableJson).digest('hex')
    return hash
  }

  /**
   * Find best day for long run (prefer sun, then sat, then nearest available)
   */
  private findLongRunDay(runningDays: TrainingDay[]): TrainingDay {
    if (runningDays.includes('sun')) return 'sun'
    if (runningDays.includes('sat')) return 'sat'
    // Find nearest weekend day or any available day
    const weekendDays: TrainingDay[] = ['sat', 'sun']
    for (const day of weekendDays) {
      if (runningDays.includes(day)) return day
    }
    // Fallback to first available day
    return runningDays[0] ?? 'sun'
  }

  /**
   * Find weekday for quality session (mon-fri from runningDays)
   */
  private findQualityDay(runningDays: TrainingDay[]): TrainingDay | null {
    const weekdays: TrainingDay[] = ['mon', 'tue', 'wed', 'thu', 'fri']
    const availableWeekdays = runningDays.filter((d) => weekdays.includes(d))
    return availableWeekdays.length > 0 ? (availableWeekdays[0] ?? null) : null
  }

  /**
   * Round to nearest 5 minutes
   */
  private roundTo5Min(value: number): number {
    return Math.round(value / 5) * 5
  }

  /**
   * Generate deterministic weekly plan from TrainingContext
   */
  generatePlan(ctx: TrainingContext, adjustments?: TrainingAdjustments): WeeklyPlan {
    const { weekStartIso, weekEndIso } = this.calculateWeekBoundaries(ctx.generatedAtIso)
    const inputsHash = this.calculateInputsHash(ctx)

    // Calculate base duration (avg week min, rounded to 5)
    const avgWeekMin = this.roundTo5Min(ctx.signals.volume.durationMin / 4)

    // Determine if we have fatigue
    const hasFatigue = ctx.signals.flags.fatigue === true
    const canHaveQuality = ctx.signals.volume.sessions >= 3 && !hasFatigue
    const runningDaysCount = ctx.profile.runningDays.length
    const canHaveStrides = runningDaysCount >= 3

    // Initialize sessions array (7 days, mon..sun)
    const sessions: PlannedSession[] = this.DAYS_ORDER.map((day) => {
      const isRunningDay = ctx.profile.runningDays.includes(day)
      return {
        day,
        type: isRunningDay ? 'easy' : 'rest',
        durationMin: 0,
      }
    })

    // Place long run
    const longRunDay = this.findLongRunDay(ctx.profile.runningDays)
    const longRunIndex = this.DAYS_ORDER.indexOf(longRunDay)
    if (longRunIndex >= 0 && sessions[longRunIndex]) {
      sessions[longRunIndex]!.type = 'long'
      sessions[longRunIndex]!.durationMin = hasFatigue ? 75 : 90 // 70-80 or default 90
      sessions[longRunIndex]!.intensityHint = 'Z2'

      // Surface hints for long run
      if (ctx.profile.surfaces.preferTrail) {
        sessions[longRunIndex]!.surfaceHint = 'trail'
      } else if (ctx.profile.surfaces.avoidAsphalt) {
        const isWeekend = longRunDay === 'sat' || longRunDay === 'sun'
        sessions[longRunIndex]!.surfaceHint = isWeekend ? 'trail' : 'track'
      }
    }

    // Place quality session (if conditions met)
    if (canHaveQuality) {
      const weekdays: TrainingDay[] = ['mon', 'tue', 'wed', 'thu', 'fri']
      const availableWeekdays = ctx.profile.runningDays.filter((d) => weekdays.includes(d))
      // Find first weekday that's still an easy session (not already long run)
      const qualityDay = availableWeekdays.find((day) => {
        const idx = this.DAYS_ORDER.indexOf(day)
        return idx >= 0 && sessions[idx]?.type === 'easy'
      })
      if (qualityDay) {
        const qualityIndex = this.DAYS_ORDER.indexOf(qualityDay)
        if (qualityIndex >= 0 && sessions[qualityIndex]) {
          sessions[qualityIndex]!.type = 'quality'
          sessions[qualityIndex]!.durationMin = 50
          sessions[qualityIndex]!.intensityHint = 'Z3'

          // Surface hints for quality (weekday)
          if (ctx.profile.surfaces.avoidAsphalt) {
            sessions[qualityIndex]!.surfaceHint = 'track'
          }
        }
      }
    }

    // Set durations for easy sessions and add strides to one
    let stridesAdded = false
    for (let i = 0; i < sessions.length; i++) {
      const session = sessions[i]!
      if (session.type === 'easy') {
        session.durationMin = hasFatigue ? 35 : 40 // 30-40 or default 40
        session.intensityHint = 'Z2'

        // Surface hints for easy (weekdays)
        if (ctx.profile.surfaces.avoidAsphalt && !this.isWeekend(session.day)) {
          session.surfaceHint = 'track'
        }

        // Add strides if conditions met (attach to first easy session)
        if (canHaveStrides && !stridesAdded) {
          session.notes = ['Include 4-6 strides (20-30s each)']
          stridesAdded = true
        }
      } else if (session.type === 'rest') {
        session.durationMin = 0
      }
    }

    // Apply adjustments (deterministic) - działa tylko na code + params
    if (adjustments?.adjustments) {
      for (const adjustment of adjustments.adjustments) {
        // reduce_load: usuń quality sesje, zmniejsz pozostałe
        if (adjustment.code === 'reduce_load') {
          const reductionPct = adjustment.params?.reductionPct ?? 20
          const reductionFactor = 1 - reductionPct / 100

          // Usuń wszystkie sesje typu 'quality' - zamień na 'easy' z stałym czasem
          for (const s of sessions) {
            if (s.type === 'quality') {
              s.type = 'easy'
              s.durationMin = 40 // stałe, deterministyczne
              s.intensityHint = 'Z2'
              delete (s as any).surfaceHint // usuń jeśli istnieje
            }
          }

          // Zmniejsz duration pozostałych sesji zgodnie z reductionPct
          for (const s of sessions) {
            if (typeof s.durationMin === 'number' && s.durationMin > 0) {
              s.durationMin = this.roundTo5Min(s.durationMin * reductionFactor)
            }
            if (typeof s.distanceKm === 'number' && Number.isFinite(s.distanceKm) && s.distanceKm > 0) {
              s.distanceKm = Number((s.distanceKm * reductionFactor).toFixed(1))
            }
          }
        }

        // recovery_focus: zamień quality na easy, skróć long
        if (adjustment.code === 'recovery_focus') {
          if (adjustment.params?.replaceHardSessionWithEasy === true) {
            // Znajdź sesję typu 'quality' → zamień na 'easy'
            for (const s of sessions) {
              if (s.type === 'quality') {
                s.type = 'easy'
                s.durationMin = 40
                s.intensityHint = 'Z2'
                delete (s as any).surfaceHint
              }
            }
          }

          if (adjustment.params?.longRunReductionPct) {
            const reductionPct = adjustment.params.longRunReductionPct
            const reductionFactor = 1 - reductionPct / 100
            // Znajdź sesję typu 'long' → zmniejsz durationMin
            for (const s of sessions) {
              if (s.type === 'long') {
                s.durationMin = this.roundTo5Min(s.durationMin * reductionFactor)
              }
            }
          }
        }

        // technique_focus: dodaj strides do easy sessions
        if (adjustment.code === 'technique_focus' && adjustment.params?.addStrides === true) {
          const stridesCount = adjustment.params.stridesCount || 6
          const stridesDurationSec = adjustment.params.stridesDurationSec || 20
          let stridesAddedCount = 0
          const maxStridesSessions = 2

          // Znajdź 1-2 sesje typu 'easy' (które jeszcze nie mają notes ze strides)
          for (const s of sessions) {
            if (s.type === 'easy' && stridesAddedCount < maxStridesSessions) {
              const hasStridesNote = s.notes?.some((note) => note.toLowerCase().includes('strides'))
              if (!hasStridesNote) {
                if (!s.notes) {
                  s.notes = []
                }
                s.notes.push(`Include ${stridesCount}x${stridesDurationSec}s strides`)
                stridesAddedCount++
              }
            }
          }
        }
      }
    }

    // Calculate summary
    const totalDurationMin = sessions.reduce((sum, s) => sum + s.durationMin, 0)
    const qualitySessions = sessions.filter((s) => s.type === 'quality').length
    const longRunSession = sessions.find((s) => s.type === 'long')
    const longRunDayResult: TrainingDay | undefined = longRunSession?.day

    // Build rationale
    const rationale: string[] = []
    rationale.push(`Weekly plan based on last ${ctx.windowDays} days window`)
    if (hasFatigue) {
      rationale.push('No quality session due to fatigue flag')
      rationale.push('Reduced durations due to fatigue')
    } else if (canHaveQuality) {
      rationale.push('Quality session scheduled based on volume and recovery status')
    }
    if (longRunSession?.surfaceHint === 'trail') {
      if (ctx.profile.surfaces.preferTrail) {
        rationale.push('Long run scheduled on trail due to surface preference')
      } else if (ctx.profile.surfaces.avoidAsphalt) {
        rationale.push('Long run scheduled on trail to avoid asphalt')
      }
    }
    if (canHaveStrides) {
      rationale.push('Strides included in easy session (≥3 running days)')
    }

    const plan: WeeklyPlan = {
      generatedAtIso: ctx.generatedAtIso,
      weekStartIso,
      weekEndIso,
      windowDays: ctx.windowDays,
      inputsHash,
      sessions,
      summary: {
        totalDurationMin,
        qualitySessions,
        ...(longRunDayResult !== undefined && { longRunDay: longRunDayResult }),
      },
      rationale,
    }

    // Validate with Zod schema
    const parsed = weeklyPlanSchema.safeParse(plan)
    if (!parsed.success) {
      throw new InternalServerErrorException(
        `WeeklyPlan validation failed: ${JSON.stringify(parsed.error.format())}`,
      )
    }

    return parsed.data
  }

  private isWeekend(day: TrainingDay): boolean {
    return day === 'sat' || day === 'sun'
  }
}

