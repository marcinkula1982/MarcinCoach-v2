import {
  Injectable,
  BadRequestException,
  NotFoundException,
  ConflictException,
} from '@nestjs/common'
import { PrismaService } from '../prisma.service'
import { SaveWorkoutDto } from './dto/save-workout.dto'
import { UpdateWorkoutMetaDto } from './dto/update-workout-meta.dto'
import type { Express } from 'express'

@Injectable()
export class WorkoutsService {
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

  async create(userExternalId: string, dto: SaveWorkoutDto) {
    if (!userExternalId) {
      throw new BadRequestException('Missing user from session')
    }

    const user = await this.prisma.user.upsert({
      where: { externalId: userExternalId },
      update: {},
      create: { externalId: userExternalId },
    })

    // anti-duplicate (same user + same tcx start time + same duration + same distance)
    // NOTE: Race condition possible - two parallel requests may both pass this check.
    // TODO: Use unique hash (e.g., sha1(startTime + duration + distance + userId)) or partial unique index
    const startTimeIso = (dto as any)?.summary?.startTimeIso ?? null
    const durationSec =
      (dto as any)?.summary?.trimmed?.durationSec ??
      (dto as any)?.summary?.original?.durationSec ??
      null
    const distanceM =
      (dto as any)?.summary?.trimmed?.distanceM ??
      (dto as any)?.summary?.original?.distanceM ??
      null

    if (startTimeIso && durationSec != null && distanceM != null) {
      const recent = await this.prisma.workout.findMany({
        where: { userId: user.id },
        orderBy: { createdAt: 'desc' },
        take: 50,
        select: { id: true, summary: true },
      })

      for (const w of recent) {
        const parsedSummary = this.safeJsonParse(w.summary)
        if (!parsedSummary) continue

        const candidateStart = parsedSummary?.startTimeIso ?? null
        const candidateDuration =
          parsedSummary?.trimmed?.durationSec ?? parsedSummary?.original?.durationSec ?? null
        const candidateDistance =
          parsedSummary?.trimmed?.distanceM ?? parsedSummary?.original?.distanceM ?? null

        if (
          candidateStart === startTimeIso &&
          candidateDuration === durationSec &&
          candidateDistance === distanceM
        ) {
          throw new ConflictException('Workout already exists')
        }
      }
    }

    const workout = await this.prisma.workout.create({
      data: {
        userId: user.id,
        action: dto.action,
        kind: dto.kind,
        summary: JSON.stringify(dto.summary),
        raceMeta: dto.raceMeta ? JSON.stringify(dto.raceMeta) : null,
        workoutMeta: dto.workoutMeta ? JSON.stringify(dto.workoutMeta) : null,
        tcxRaw: dto.tcxRaw,
        raw: {
          create: {
            xml: dto.tcxRaw,
          },
        },
      },
      include: {
        raw: false,
      },
    })

    return {
      id: workout.id,
      userId: userExternalId,
      action: workout.action,
      kind: workout.kind,
      summary: this.safeJsonParse(workout.summary) ?? {},
      raceMeta: this.safeJsonParse(workout.raceMeta) ?? undefined,
      workoutMeta: this.safeJsonParse(workout.workoutMeta) ?? undefined,
      createdAt: workout.createdAt,
    }
  }

  async findAllForUser(userExternalId: string) {
    const user = await this.prisma.user.findUnique({
      where: { externalId: userExternalId },
    })

    if (!user) return []

    const workouts = await this.prisma.workout.findMany({
      where: { userId: user.id },
      select: {
        id: true,
        action: true,
        kind: true,
        summary: true,
        raceMeta: true,
        workoutMeta: true,
        createdAt: true,
        // tcxRaw intentionally omitted to avoid large payloads
      },
      orderBy: { createdAt: 'desc' },
    })

    return workouts.map((w) => ({
      id: w.id,
      userId: userExternalId,
      action: w.action,
      kind: w.kind,
      summary: this.safeJsonParse(w.summary) ?? {},
      raceMeta: this.safeJsonParse(w.raceMeta) ?? undefined,
      workoutMeta: this.safeJsonParse(w.workoutMeta) ?? undefined,
      createdAt: w.createdAt,
    }))
  }

  async uploadTcxFile(file: Express.Multer.File, userExternalId: string) {
    if (!file || !file.buffer) {
      throw new BadRequestException('Brak pliku do zapisu')
    }

    const rawTcx = file.buffer.toString('utf-8')
    if (!rawTcx.trim()) {
      throw new BadRequestException('Plik jest pusty')
    }

    const user = await this.prisma.user.upsert({
      where: { externalId: userExternalId },
      update: {},
      create: { externalId: userExternalId },
    })

    const workout = await this.prisma.workout.create({
      data: {
        userId: user.id,
        action: 'upload',
        kind: 'training',
        summary: JSON.stringify({
          fileName: file.originalname ?? 'upload.tcx',
          totalPoints: 0,
          selectedPoints: 0,
        }),
        raceMeta: null,
        tcxRaw: rawTcx,
        raw: {
          create: {
            xml: rawTcx,
          },
        },
      },
      include: { raw: false },
    })

    return {
      id: workout.id,
      userId: userExternalId,
      action: workout.action,
      kind: workout.kind,
      summary: this.safeJsonParse(workout.summary) ?? {},
      createdAt: workout.createdAt,
    }
  }

  async findOneById(id: number, includeRaw = false) {
    const w = await this.prisma.workout.findUnique({
      where: { id },
      select: {
        id: true,
        userId: true,
        action: true,
        kind: true,
        summary: true,
        raceMeta: true,
        workoutMeta: true,
        createdAt: true,
        updatedAt: true,
        tcxRaw: includeRaw,
      },
    })

    if (!w) throw new NotFoundException('Workout not found')

    return {
      id: w.id,
      userId: w.userId,
      action: w.action,
      kind: w.kind,
      summary: this.safeJsonParse(w.summary) ?? {},
      raceMeta: this.safeJsonParse(w.raceMeta) ?? undefined,
      workoutMeta: this.safeJsonParse(w.workoutMeta) ?? undefined,
      createdAt: w.createdAt,
      updatedAt: w.updatedAt,
      tcxRaw: includeRaw ? (w as any).tcxRaw : undefined,
    }
  }

  async findOneForUser(id: number, username: string, includeRaw = false) {
    const workout = await this.prisma.workout.findFirst({
      where: {
        id,
        user: { externalId: username },
      },
      select: {
        id: true,
        userId: true,
        action: true,
        kind: true,
        summary: true,
        raceMeta: true,
        workoutMeta: true,
        createdAt: true,
        updatedAt: true,
        tcxRaw: includeRaw,
      },
    })

    if (!workout) {
      throw new NotFoundException('Workout not found')
    }

    return {
      id: workout.id,
      userId: username,
      action: workout.action,
      kind: workout.kind,
      summary: this.safeJsonParse(workout.summary) ?? {},
      raceMeta: this.safeJsonParse(workout.raceMeta) ?? undefined,
      workoutMeta: this.safeJsonParse(workout.workoutMeta) ?? undefined,
      createdAt: workout.createdAt,
      updatedAt: workout.updatedAt,
      tcxRaw: includeRaw ? (workout as any).tcxRaw : undefined,
    }
  }

  async updateMeta(
    id: number,
    workoutMeta: UpdateWorkoutMetaDto['workoutMeta'],
    username: string,
  ) {
    const workout = await this.prisma.workout.findFirst({
      where: {
        id,
        user: { externalId: username },
      },
      select: { id: true },
    })

    if (!workout) {
      throw new NotFoundException('Workout not found')
    }

    const updated = await this.prisma.workout.update({
      where: { id },
      data: {
        workoutMeta: workoutMeta ? JSON.stringify(workoutMeta) : null,
      },
      select: {
        id: true,
        workoutMeta: true,
        updatedAt: true,
      },
    })

    return {
      id: updated.id,
      workoutMeta: this.safeJsonParse(updated.workoutMeta) ?? null,
      updatedAt: updated.updatedAt,
    }
  }

  async deleteByIdForUser(id: number, username: string) {
    const workout = await this.prisma.workout.findFirst({
      where: {
        id,
        user: { externalId: username },
      },
      select: { id: true },
    })

    if (!workout) {
      throw new NotFoundException('Workout not found')
    }

    return this.prisma.workout.delete({
      where: { id },
    })
  }

  /**
   * Returns workout analytics data in a flattened format
   * Similar to SQL query extracting workout_dt, distance_km, duration_min, and workoutMeta fields
   */
  async getAnalyticsForUser(userExternalId: string) {
    const user = await this.prisma.user.findUnique({
      where: { externalId: userExternalId },
    })

    if (!user) return []

    const workouts = await this.prisma.workout.findMany({
      where: { userId: user.id },
      select: {
        id: true,
        createdAt: true,
        summary: true,
        workoutMeta: true,
      },
      orderBy: { createdAt: 'desc' },
    })

    return workouts.map((w) => {
      const summary = this.safeJsonParse<{
        startTimeIso?: string | null
        trimmed?: { distanceM?: number; durationSec?: number }
        original?: { distanceM?: number; durationSec?: number }
      }>(w.summary) ?? {}

      const meta = this.safeJsonParse<{
        planCompliance?: string
        rpe?: number | null
        fatigueFlag?: boolean
        note?: string
      }>(w.workoutMeta) ?? {}

      // workout_dt: startTimeIso fallback to createdAt
      const workoutDt = summary.startTimeIso
        ? new Date(summary.startTimeIso)
        : w.createdAt

      // distance_km: trimmed fallback to original, convert m to km
      const distanceM =
        summary.trimmed?.distanceM ?? summary.original?.distanceM ?? null
      const distanceKm = distanceM != null ? distanceM / 1000.0 : null

      // duration_min: trimmed fallback to original, convert sec to min
      const durationSec =
        summary.trimmed?.durationSec ?? summary.original?.durationSec ?? null
      const durationMin = durationSec != null ? durationSec / 60.0 : null

      return {
        id: w.id,
        createdAt: w.createdAt,
        workoutDt,
        distanceKm,
        durationMin,
        planCompliance: meta.planCompliance ?? null,
        rpe: meta.rpe ?? null,
        fatigueFlag: meta.fatigueFlag ?? false,
        note: meta.note ?? null,
      }
    })
  }
}

