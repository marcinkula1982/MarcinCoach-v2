import { Injectable, InternalServerErrorException, NotFoundException } from '@nestjs/common'
import { PrismaService } from '../prisma.service'
import type { TrainingFeedbackV2 } from './training-feedback-v2.types'
import { trainingFeedbackV2Schema } from './training-feedback-v2.schema'
import {
  determineCharacter,
  analyzeHrStability,
  analyzeEconomy,
  calculateLoadImpact,
  calculateCoachSignals,
  calculateMetrics,
} from './training-feedback-v2-rules'
import { parseTcx } from '../utils/tcxParser'
import type { WorkoutSummary } from '../types/workout.types'
import type { IntensityBuckets } from '../types/metrics.types'
import { normalizeLegacyFeedback, parseAndNormalizeFeedbackRecord } from './training-feedback-v2-normalize'
import type { FeedbackSignals } from './feedback-signals.types'
import { mapFeedbackToSignals } from './feedback-signals.mapper'

@Injectable()
export class TrainingFeedbackV2Service {
  constructor(private readonly prisma: PrismaService) {}

  private safeJsonParse<T = any>(val: any): T | null {
    if (val == null) return null
    if (typeof val === 'string') {
      try {
        return JSON.parse(val) as T
      } catch {
        return null
      }
    }
    return val as T
  }

  async generateFeedback(workoutId: number, userId: number): Promise<TrainingFeedbackV2> {
    // Pobierz workout
    const workout = await this.prisma.workout.findFirst({
      where: {
        id: workoutId,
        userId,
      },
      select: {
        id: true,
        summary: true,
        workoutMeta: true,
        tcxRaw: true,
      },
    })

    if (!workout) {
      throw new NotFoundException('Workout not found')
    }

    const summary = this.safeJsonParse<WorkoutSummary>(workout.summary) ?? {
      totalPoints: 0,
      selectedPoints: 0,
    }
    const durationSec = summary.trimmed?.durationSec ?? summary.original?.durationSec ?? 0

    // Pobierz intensity buckets
    let intensity: IntensityBuckets | null = null
    if (summary.intensity) {
      if (typeof summary.intensity === 'string') {
        intensity = this.safeJsonParse<IntensityBuckets>(summary.intensity)
      } else if (typeof summary.intensity === 'object') {
        intensity = summary.intensity as IntensityBuckets
      }
    }

    // Parse trackpoints dla HR i pace analysis
    let trackpoints: Array<{ time: string; distanceMeters?: number; heartRateBpm?: number }> = []
    if (workout.tcxRaw) {
      try {
        const parsed = parseTcx(workout.tcxRaw)
        trackpoints = parsed.trackpoints
      } catch {
        // Jeśli parsing się nie powiedzie, użyj pustej listy
        trackpoints = []
      }
    }

    // Wywołaj deterministyczne reguły
    const character = determineCharacter(intensity, durationSec)
    const hrStability = analyzeHrStability(trackpoints)
    const economy = analyzeEconomy(trackpoints)
    const loadImpact = calculateLoadImpact(summary)

    // Wygeneruj coachSignals
    const partialFeedback = {
      character,
      hrStability,
      economy,
      loadImpact,
    }
    const coachSignals = calculateCoachSignals(partialFeedback)
    const metrics = calculateMetrics({ hrStability, economy, loadImpact })

    // Zbuduj pełny feedback (bez coachConclusion i generatedAtIso - generowane na wyjściu)
    const feedback: TrainingFeedbackV2 = {
      character,
      hrStability,
      economy,
      loadImpact,
      coachSignals,
      metrics,
      workoutId,
    }

    // Walidacja
    const parsed = trainingFeedbackV2Schema.safeParse(feedback)
    if (!parsed.success) {
      throw new InternalServerErrorException(
        `TrainingFeedbackV2 validation failed: ${JSON.stringify(parsed.error.format())}`,
      )
    }

    const validatedFeedback = parsed.data

    // Zapisz snapshot do DB
    await (this.prisma as any).trainingFeedbackV2.upsert({
      where: { workoutId },
      create: {
        workoutId,
        userId,
        feedback: JSON.stringify(validatedFeedback),
      },
      update: {
        feedback: JSON.stringify(validatedFeedback),
      },
    })

    return validatedFeedback
  }

  async getFeedbackForWorkout(workoutId: number, userId: number): Promise<{ id: number; feedback: TrainingFeedbackV2; createdAt: Date } | null> {
    const record = await (this.prisma as any).trainingFeedbackV2.findFirst({
      where: {
        workoutId,
        userId,
      },
    })

    if (!record) {
      return null
    }

    const feedbackRaw = this.safeJsonParse<any>(record.feedback)
    if (!feedbackRaw) {
      return null
    }

    // TODO: Remove after V1 → V2 data migration
    // This normalization handles old snapshot formats
    const feedback = normalizeLegacyFeedback(feedbackRaw)
    if (!feedback) {
      return null
    }

    // Walidacja
    const parsed = trainingFeedbackV2Schema.safeParse(feedback)
    if (!parsed.success) {
      return null
    }

    return {
      id: record.id,
      feedback: parsed.data,
      createdAt: record.createdAt,
    }
  }

  async getLatestFeedbackSignalsForUser(userId: number): Promise<FeedbackSignals | undefined> {
    // Pobierz candidate records z join do Workout (orderBy id desc dla wydajności take: 50)
    const candidates = await (this.prisma as any).trainingFeedbackV2.findMany({
      where: { userId },
      include: {
        workout: {
          select: {
            summary: true,
            createdAt: true,
          },
        },
      },
      orderBy: { id: 'desc' },
      take: 50,
    })

    if (candidates.length === 0) {
      return undefined
    }

    // Wyznacz workoutDt dla każdego rekordu i znajdź najlepszy
    let bestRecord: typeof candidates[0] | null = null
    let bestWorkoutDt = -1
    let bestId = -1

    for (const record of candidates) {
      // Parsuj workout.summary
      const summary = this.safeJsonParse<WorkoutSummary>(record.workout?.summary)
      
      // Wyznacz workoutDt: startTimeIso jeśli istnieje, else createdAt
      let workoutDt: number
      if (summary?.startTimeIso) {
        const startTime = new Date(summary.startTimeIso)
        if (Number.isFinite(startTime.getTime())) {
          workoutDt = startTime.getTime()
        } else {
          // Nieprawidłowy ISO, fallback do createdAt
          workoutDt = record.workout?.createdAt ? new Date(record.workout.createdAt).getTime() : 0
        }
      } else {
        // Fallback do workout.createdAt
        workoutDt = record.workout?.createdAt ? new Date(record.workout.createdAt).getTime() : 0
      }

      // Wybierz rekord z maksymalnym workoutDt
      // Tie-breaker: gdy workoutDt równe, wybierz większe trainingFeedbackV2.id
      if (workoutDt > bestWorkoutDt || (workoutDt === bestWorkoutDt && record.id > bestId)) {
        bestRecord = record
        bestWorkoutDt = workoutDt
        bestId = record.id
      }
    }

    if (!bestRecord) {
      return undefined
    }

    // Parsuj i znormalizuj feedback
    const feedback = parseAndNormalizeFeedbackRecord(bestRecord)
    if (!feedback) {
      // Jeśli parseAndNormalizeFeedbackRecord zwraca null, spróbuj kolejny najlepszy
      // (w praktyce powinno być rzadkie, ale obsługujemy to)
      const remainingCandidates = candidates.filter((r: any) => r.id !== bestRecord.id)
      for (const record of remainingCandidates) {
        const feedback = parseAndNormalizeFeedbackRecord(record)
        if (feedback) {
          return mapFeedbackToSignals(feedback)
        }
      }
      return undefined
    }

    // Mapuj do FeedbackSignals
    return mapFeedbackToSignals(feedback)
  }
}

