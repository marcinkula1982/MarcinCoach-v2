import { Injectable, InternalServerErrorException } from '@nestjs/common'
import { PrismaService } from '../prisma.service'
import type { PlanFeedbackSignals, PlanCompliance } from './training-feedback.types'
import { planFeedbackSignalsSchema } from './training-feedback.schema'

type WorkoutRow = {
  id: number
  createdAt: Date
  summary: any
  workoutMeta: any
}

@Injectable()
export class TrainingFeedbackService {
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

  private parseWorkoutMeta(raw: any): {
    planCompliance?: 'planned' | 'modified' | 'unplanned' | null
    rpe?: number | null
    fatigueFlag?: boolean
    note?: string | null
  } {
    if (!raw) return {}
    try {
      const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw
      return {
        planCompliance: parsed?.planCompliance ?? null,
        rpe: parsed?.rpe ?? null,
        fatigueFlag: parsed?.fatigueFlag ?? undefined,
        note: parsed?.note ?? null,
      }
    } catch {
      return {}
    }
  }

  private getWorkoutDt(summary: any, createdAt: Date): Date {
    return summary?.startTimeIso ? new Date(summary.startTimeIso) : createdAt
  }

  private mapCompliance(value: any): PlanCompliance {
    if (value === 'planned' || value === 'modified' || value === 'unplanned') {
      return value
    }
    return 'unknown'
  }

  private median(arr: number[]): number {
    if (arr.length === 0) return 0
    const sorted = [...arr].sort((a, b) => a - b)
    const mid = Math.floor(sorted.length / 2)
    return sorted.length % 2 === 0
      ? (sorted[mid - 1]! + sorted[mid]!) / 2
      : sorted[mid]!
  }

  async getFeedbackForUser(userId: number, opts?: { days?: number }): Promise<PlanFeedbackSignals> {
    const days = opts?.days ?? 28

    // Fetch recent workouts (same pattern as TrainingSignalsService)
    const workouts: WorkoutRow[] = await this.prisma.workout.findMany({
      where: { userId },
      select: { id: true, createdAt: true, summary: true, workoutMeta: true },
      orderBy: { createdAt: 'desc' },
      take: 500, // reasonable upper bound
    })

    // Parse workouts and calculate workoutDt
    const rows = workouts.map((w) => {
      const summary = this.parseSummary(w.summary)
      const workoutDt = this.getWorkoutDt(summary, w.createdAt)
      const meta = this.parseWorkoutMeta(w.workoutMeta)

      return {
        id: w.id,
        workoutDt,
        planCompliance: this.mapCompliance(meta.planCompliance),
        rpe: meta.rpe,
        fatigueFlag: meta.fatigueFlag,
        note: meta.note,
      }
    })

    // Determine 'to' deterministically from data (same pattern as TrainingSignalsService)
    const to =
      rows.length > 0
        ? rows.reduce((max, r) => (r.workoutDt > max ? r.workoutDt : max), rows[0]!.workoutDt)
        : new Date(0)

    // Calculate 'from' relative to deterministic 'to'
    const from = new Date(to.getTime() - days * 24 * 60 * 60 * 1000)

    // Filter by date window based on workoutDt
    const filtered = rows.filter((r) => r.workoutDt >= from && r.workoutDt <= to)

    // Aggregate counts
    const totalSessions = filtered.length
    const planned = filtered.filter((r) => r.planCompliance === 'planned').length
    const modified = filtered.filter((r) => r.planCompliance === 'modified').length
    const unplanned = filtered.filter((r) => r.planCompliance === 'unplanned').length
    const unknown = filtered.filter((r) => r.planCompliance === 'unknown').length

    // Calculate complianceRate (2 decimals)
    const plannedPct = totalSessions > 0 ? Number(((planned / totalSessions) * 100).toFixed(2)) : 0
    const modifiedPct = totalSessions > 0 ? Number(((modified / totalSessions) * 100).toFixed(2)) : 0
    const unplannedPct = totalSessions > 0 ? Number(((unplanned / totalSessions) * 100).toFixed(2)) : 0

    // Aggregate RPE (valid numeric values 1..10)
    const validRpeValues = filtered
      .map((r) => r.rpe)
      .filter((rpe): rpe is number => typeof rpe === 'number' && Number.isFinite(rpe) && rpe >= 1 && rpe <= 10)

    const rpeSamples = validRpeValues.length
    const rpeAvg = rpeSamples > 0 ? Number((validRpeValues.reduce((sum, v) => sum + v, 0) / rpeSamples).toFixed(1)) : undefined
    const rpeP50 = rpeSamples > 0 ? Number(this.median(validRpeValues).toFixed(1)) : undefined

    // Aggregate fatigue
    const fatigueTrueCount = filtered.filter((r) => r.fatigueFlag === true).length
    const fatigueFalseCount = filtered.filter((r) => r.fatigueFlag === false).length

    // Aggregate notes (last 5, sorted desc by workoutDt, trimmed, non-empty)
    const notesWithValues = filtered
      .filter((r) => r.note && typeof r.note === 'string' && r.note.trim().length > 0)
      .sort((a, b) => b.workoutDt.getTime() - a.workoutDt.getTime())
      .slice(0, 5)
      .map((r) => ({
        workoutId: r.id,
        workoutDtIso: r.workoutDt.toISOString(),
        note: r.note!.trim(),
      }))

    const notesSamples = filtered.filter((r) => r.note && typeof r.note === 'string' && r.note.trim().length > 0).length

    // Build PlanFeedbackSignals
    const feedback = {
      generatedAtIso: to.toISOString(), // Deterministic from data
      windowDays: days,
      counts: {
        totalSessions,
        planned,
        modified,
        unplanned,
        unknown,
      },
      complianceRate: {
        plannedPct,
        modifiedPct,
        unplannedPct,
      },
      rpe: {
        samples: rpeSamples,
        ...(rpeAvg !== undefined ? { avg: rpeAvg } : {}),
        ...(rpeP50 !== undefined ? { p50: rpeP50 } : {}),
      },
      fatigue: {
        trueCount: fatigueTrueCount,
        falseCount: fatigueFalseCount,
      },
      notes: {
        samples: notesSamples,
        last5: notesWithValues,
      },
    } as PlanFeedbackSignals

    // Validate with Zod schema
    const parsed = planFeedbackSignalsSchema.safeParse(feedback)
    if (!parsed.success) {
      throw new InternalServerErrorException(
        `PlanFeedbackSignals validation failed: ${JSON.stringify(parsed.error.format())}`,
      )
    }

    return parsed.data as PlanFeedbackSignals
  }
}


