import { Injectable, InternalServerErrorException } from '@nestjs/common'
import { PrismaService } from '../prisma.service'
import type { TrainingSignals, TrainingSignalsIntensity } from './training-signals.types'
import { trainingSignalsSchema } from './training-signals.schema'
import { accumulateIntensity, emptyIntensity } from './training-signals.utils'

type WorkoutRow = {
  id: number
  createdAt: Date
  summary: any
}

@Injectable()
export class TrainingSignalsService {
  constructor(private readonly prisma: PrismaService) {}

  private parseSummary(raw: any) {
    if (typeof raw === 'string') {
      try {
        return JSON.parse(raw)
      } catch {
        return {}
      }
    }
    return raw ?? {}
  }

  private getWorkoutDt(summary: any, createdAt: Date) {
    return summary?.startTimeIso ? new Date(summary.startTimeIso) : createdAt
  }

  private toIsoDate(d: Date) {
    return new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate())).toISOString()
  }

  private sumIntensity(intensity: any, durationSec: number | null): TrainingSignalsIntensity {
    if (!intensity) {
      return {
        z1Sec: 0,
        z2Sec: 0,
        z3Sec: 0,
        z4Sec: 0,
        z5Sec: 0,
        totalSec: durationSec ?? 0,
      }
    }
    return {
      z1Sec: intensity.z1Sec ?? 0,
      z2Sec: intensity.z2Sec ?? 0,
      z3Sec: intensity.z3Sec ?? 0,
      z4Sec: intensity.z4Sec ?? 0,
      z5Sec: intensity.z5Sec ?? 0,
      totalSec:
        intensity.totalSec ??
        [
          intensity.z1Sec ?? 0,
          intensity.z2Sec ?? 0,
          intensity.z3Sec ?? 0,
          intensity.z4Sec ?? 0,
          intensity.z5Sec ?? 0,
        ].reduce((a: number, b: number) => a + b, 0) ??
        durationSec ??
        0,
    }
  }

  private isoWeekKey(d: Date) {
    const date = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()))
    const day = date.getUTCDay() || 7
    date.setUTCDate(date.getUTCDate() + 4 - day)
    const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1))
    const weekNo = Math.ceil((((date.getTime() - yearStart.getTime()) / 86400000) + 1) / 7)
    const year = date.getUTCFullYear()
    return `${year}-W${String(weekNo).padStart(2, '0')}`
  }

  async getSignalsForUser(userId: number, opts?: { days?: number }): Promise<TrainingSignals> {
    const days = opts?.days ?? 28

    // Fetch recent workouts (don't filter by createdAt in Prisma - filter by workoutDt in memory)
    const workouts: WorkoutRow[] = await this.prisma.workout.findMany({
      where: { userId },
      select: { id: true, createdAt: true, summary: true },
      orderBy: { createdAt: 'desc' },
      take: 500, // reasonable upper bound
    })

    const rows = workouts
      .map((w) => {
        const summary = this.parseSummary(w.summary)
        const workoutDt = this.getWorkoutDt(summary, w.createdAt)
        const distanceM = summary?.trimmed?.distanceM ?? summary?.original?.distanceM ?? null
        const durationSec = summary?.trimmed?.durationSec ?? summary?.original?.durationSec ?? null

        // Load value: summary.intensity as number (not buckets)
        const loadValue = typeof summary?.intensity === 'number' ? summary.intensity : 0

        // Intensity buckets: look for object field (e.g., intensityBuckets), not summary.intensity
        const buckets =
          summary?.intensityBuckets ??
          (typeof summary?.intensity === 'object' ? summary.intensity : null)
        const intensity = this.sumIntensity(buckets, durationSec)

        return {
          id: w.id,
          workoutDt,
          distanceKm: distanceM != null ? distanceM / 1000 : 0,
          durationMin: durationSec != null ? durationSec / 60 : 0,
          intensity,
          loadValue,
        }
      })
      .filter((r) => Number.isFinite(r.distanceKm) && Number.isFinite(r.durationMin))
      .filter((r) => r.distanceKm > 0 || r.durationMin > 0)

    // Determine 'to' deterministically from data
    // If there are workouts, use max workoutDt; otherwise use epoch (1970-01-01)
    const to =
      rows.length > 0
        ? rows.reduce((max, r) => (r.workoutDt > max ? r.workoutDt : max), rows[0]!.workoutDt)
        : new Date(0)

    // Calculate 'from' relative to deterministic 'to'
    const from = new Date(to.getTime() - days * 24 * 60 * 60 * 1000)

    // Filter by date window based on workoutDt (not createdAt)
    const filtered = rows.filter((r) => r.workoutDt >= from && r.workoutDt <= to)

    // Volume
    const volumeDistance = filtered.reduce((s, r) => s + (r.distanceKm ?? 0), 0)
    const volumeDuration = filtered.reduce((s, r) => s + (r.durationMin ?? 0), 0)
    const sessions = filtered.length

    // Intensity
    const totalIntensity = filtered.reduce(
      (acc, r) => accumulateIntensity(acc, r.intensity),
      emptyIntensity(),
    )

    // Long run
    const longRunRow =
      filtered.reduce(
        (best, cur) => (cur.distanceKm > (best?.distanceKm ?? 0) ? cur : best),
        null as (typeof filtered)[number] | null,
      ) ?? null

    const longRun = {
      exists: Boolean(longRunRow),
      distanceKm: longRunRow?.distanceKm ?? 0,
      durationMin: longRunRow?.durationMin ?? 0,
      workoutId: longRunRow?.id ?? null,
      workoutDt: longRunRow ? longRunRow.workoutDt.toISOString() : null,
    }

    // Load: from summary.intensity (number), not from buckets
    // Weekly load calculated relative to window 'to', not current time
    const weeklyFrom = new Date(to.getTime() - 7 * 24 * 60 * 60 * 1000)
    const weeklyLoad = filtered
      .filter((r) => r.workoutDt > weeklyFrom)
      .reduce((s, r) => s + (r.loadValue ?? 0), 0)

    const rolling4wLoad = filtered.reduce((s, r) => s + (r.loadValue ?? 0), 0)

    // Consistency
    const sessionsPerWeek = sessions > 0 ? Number((sessions / 4).toFixed(2)) : 0

    const weeksMap = new Map<string, number>()
    for (const r of filtered) {
      const wk = this.isoWeekKey(r.workoutDt)
      weeksMap.set(wk, (weeksMap.get(wk) ?? 0) + 1)
    }

    // streakWeeks: consecutive weeks backwards from window 'to' ISO (Monday 00:00Z) with >=1 session
    // CRITICAL: Use 'to' as anchor, not current date
    const anchor = to

    // Get start of ISO week (Monday 00:00 UTC)
    const getWeekStart = (d: Date): Date => {
      const date = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()))
      const day = date.getUTCDay() || 7 // Monday = 1, Sunday = 7
      date.setUTCDate(date.getUTCDate() - (day - 1)) // Go to Monday
      date.setUTCHours(0, 0, 0, 0)
      return date
    }

    let streak = 0
    let weekStart = getWeekStart(anchor)

    while (true) {
      const weekKey = this.isoWeekKey(weekStart)
      const count = weeksMap.get(weekKey) ?? 0
      if (count >= 1) {
        streak += 1
        // Move back 7 days
        weekStart = new Date(weekStart.getTime() - 7 * 24 * 60 * 60 * 1000)
      } else {
        break
      }
      // avoid infinite loop safeguard
      if (streak > 104) break
    }

    const result: TrainingSignals = {
      period: { from: from.toISOString(), to: to.toISOString() },
      volume: {
        distanceKm: Number(volumeDistance.toFixed(2)),
        durationMin: Number(volumeDuration.toFixed(2)),
        sessions,
      },
      intensity: {
        z1Sec: Number(totalIntensity.z1Sec.toFixed(0)),
        z2Sec: Number(totalIntensity.z2Sec.toFixed(0)),
        z3Sec: Number(totalIntensity.z3Sec.toFixed(0)),
        z4Sec: Number(totalIntensity.z4Sec.toFixed(0)),
        z5Sec: Number(totalIntensity.z5Sec.toFixed(0)),
        totalSec: Number(totalIntensity.totalSec.toFixed(0)),
      },
      longRun,
      load: {
        weeklyLoad: Number(weeklyLoad.toFixed(0)),
        rolling4wLoad: Number(rolling4wLoad.toFixed(0)),
      },
      consistency: {
        sessionsPerWeek,
        streakWeeks: streak,
      },
      flags: {
        injuryRisk: false,
        fatigue: false,
      },
    }

    const parsed = trainingSignalsSchema.safeParse(result)
    if (!parsed.success) {
      throw new InternalServerErrorException(
        `TrainingSignals validation failed: ${JSON.stringify(parsed.error.format())}`,
      )
    }

    return parsed.data
  }
}

