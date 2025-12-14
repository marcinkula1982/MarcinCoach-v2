import {
  Injectable,
  BadRequestException,
  NotFoundException,
  ConflictException,
} from '@nestjs/common'
import { PrismaService } from '../prisma.service'
import { SaveWorkoutDto } from './dto/save-workout.dto'
import { UpdateWorkoutMetaDto } from './dto/update-workout-meta.dto'
import { ImportWorkoutDto } from './dto/import-workout.dto'
import { parseTcx } from '../utils/tcxParser'
import { computeMetrics } from '../utils/metrics'
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

    console.log('[CREATE] userExternalId:', userExternalId)
    console.log('[CREATE] startTimeIso:', startTimeIso)
    console.log('[CREATE] durationSec:', durationSec)
    console.log('[CREATE] distanceM:', distanceM)

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

        console.log('[CREATE] Comparing with workout id:', w.id)
        console.log('[CREATE] candidateStart:', candidateStart, 'vs', startTimeIso)
        console.log('[CREATE] candidateDuration:', candidateDuration, 'vs', durationSec)
        console.log('[CREATE] candidateDistance:', candidateDistance, 'vs', distanceM)

        if (
          candidateStart === startTimeIso &&
          candidateDuration === durationSec &&
          candidateDistance === distanceM
        ) {
          console.log('[CREATE] DUPLICATE DETECTED -> 409')
          console.log('[CREATE] Match: workout id', w.id)
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

    const parsed = parseTcx(rawTcx)
    const metrics = computeMetrics(parsed.trackpoints)

    const summary = {
      fileName: file.originalname ?? 'upload.tcx',
      startTimeIso: parsed.startTimeIso,
      original: metrics,
      trimmed: metrics,
      totalPoints: parsed.trackpoints.length,
      selectedPoints: parsed.trackpoints.length,
    }

    console.log('[UPLOAD] userExternalId:', userExternalId)
    console.log('[UPLOAD] startTimeIso:', summary.startTimeIso)
    console.log('[UPLOAD] durationSec:', summary.trimmed?.durationSec ?? summary.original?.durationSec)
    console.log('[UPLOAD] distanceM:', summary.trimmed?.distanceM ?? summary.original?.distanceM)

    const user = await this.prisma.user.upsert({
      where: { externalId: userExternalId },
      update: {},
      create: { externalId: userExternalId },
    })

    // anti-duplicate (same user + same tcx start time + same duration + same distance)
    // NOTE: Race condition possible - two parallel requests may both pass this check.
    // TODO: Use unique hash (e.g., sha1(startTime + duration + distance + userId)) or partial unique index
    const startTimeIso = summary.startTimeIso ?? null
    const durationSec = summary.trimmed?.durationSec ?? summary.original?.durationSec ?? null
    const distanceM = summary.trimmed?.distanceM ?? summary.original?.distanceM ?? null

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

        console.log('[UPLOAD] Comparing with workout id:', w.id)
        console.log('[UPLOAD] candidateStart:', candidateStart, 'vs', startTimeIso)
        console.log('[UPLOAD] candidateDuration:', candidateDuration, 'vs', durationSec)
        console.log('[UPLOAD] candidateDistance:', candidateDistance, 'vs', distanceM)

        // Normalizacja liczb (zaokrąglenie do int) dla porównania
        const norm = (v: number | null) => (v != null ? Math.round(v) : null)

        if (
          candidateStart === startTimeIso &&
          norm(candidateDuration) === norm(durationSec) &&
          norm(candidateDistance) === norm(distanceM)
        ) {
          console.log('[UPLOAD] DUPLICATE DETECTED -> 409')
          console.log('[UPLOAD] Match: workout id', w.id)
          throw new ConflictException('Workout already exists')
        }
      }
    }

    const workout = await this.prisma.workout.create({
      data: {
        userId: user.id,
        action: 'upload',
        kind: 'training',
        summary: JSON.stringify(summary),
        raceMeta: null,
        tcxRaw: rawTcx,
        source: 'MANUAL_UPLOAD',
        sourceActivityId: null,
        sourceUserId: null,
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
      raceMeta: this.safeJsonParse(workout.raceMeta) ?? undefined,
      workoutMeta: this.safeJsonParse(workout.workoutMeta) ?? undefined,
      createdAt: workout.createdAt,
    }
  }

  async importWorkout(userExternalId: string, dto: ImportWorkoutDto) {
    if (!userExternalId) {
      throw new BadRequestException('Missing user from session')
    }

    const user = await this.prisma.user.upsert({
      where: { externalId: userExternalId },
      update: {},
      create: { externalId: userExternalId },
    })

    // Walidacja: co najmniej jedno z tcxRaw/fitRaw lub sourceActivityId
    const hasRaw = Boolean(dto.tcxRaw) || Boolean(dto.fitRaw)
    const hasStrongId = Boolean(dto.sourceActivityId)

    if (!hasRaw && !hasStrongId) {
      throw new BadRequestException('Either tcxRaw/fitRaw or sourceActivityId is required')
    }

    // Normalizacja: trim i uppercase
    const normalizedSource = dto.source.toUpperCase() as 'MANUAL_UPLOAD' | 'GARMIN' | 'STRAVA'
    const normalizedSourceActivityId = dto.sourceActivityId?.trim() ?? null

    // Deduplikacja priorytetowo po (userId, source, sourceActivityId)
    if (normalizedSourceActivityId) {
      const existing = await this.prisma.workout.findFirst({
        where: {
          userId: user.id,
          source: normalizedSource,
          sourceActivityId: normalizedSourceActivityId,
        },
      })

      if (existing) {
        throw new ConflictException('Workout already exists')
      }
    }

    // Fallback do obecnej logiki (start+duration+distance) jeśli brak sourceActivityId
    if (!normalizedSourceActivityId) {
      const startTimeIso = dto.startTimeIso ?? null
      const durationSec =
        dto.summary?.trimmed?.durationSec ?? dto.summary?.original?.durationSec ?? null
      const distanceM =
        dto.summary?.trimmed?.distanceM ?? dto.summary?.original?.distanceM ?? null

      if (startTimeIso && durationSec != null && distanceM != null) {
        const recent = await this.prisma.workout.findMany({
          where: { userId: user.id },
          orderBy: { createdAt: 'desc' },
          take: 50,
          select: { id: true, summary: true },
        })

        // Normalizacja liczb (zaokrąglenie do int) dla porównania
        const norm = (v: number | null) => (v != null ? Math.round(v) : null)

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
            norm(candidateDuration) === norm(durationSec) &&
            norm(candidateDistance) === norm(distanceM)
          ) {
            throw new ConflictException('Workout already exists')
          }
        }
      }
    }

    const workoutData: any = {
      userId: user.id,
      action: 'import',
      kind: 'training',
      summary: JSON.stringify(dto.summary),
      raceMeta: null,
      workoutMeta: dto.workoutMeta ? JSON.stringify(dto.workoutMeta) : null,
      tcxRaw: dto.tcxRaw ?? null,
      fitRaw: dto.fitRaw ?? null,
      source: normalizedSource,
      sourceActivityId: normalizedSourceActivityId,
      sourceUserId: dto.sourceUserId ?? null,
    }

    // raw relacja tylko dla TCX
    if (dto.tcxRaw) {
      workoutData.raw = {
        create: {
          xml: dto.tcxRaw,
        },
      }
    }

    const workout = await this.prisma.workout.create({
      data: workoutData,
      include: { raw: false },
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

    const round = (v: number | null, d = 2) =>
      typeof v === 'number' && Number.isFinite(v) ? Math.round(v * 10 ** d) / 10 ** d : null

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
        distanceKm: round(distanceKm, 2),
        durationMin: round(durationMin, 2),
        planCompliance: meta.planCompliance ?? null,
        rpe: meta.rpe ?? null,
        fatigueFlag: meta.fatigueFlag ?? false,
        note: meta.note ?? null,
      }
    })
  }

  private isoWeekKey(d: Date) {
    // ISO week: czwartek decyduje o tygodniu
    const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()))
    const day = date.getUTCDay() || 7
    date.setUTCDate(date.getUTCDate() + 4 - day)
    const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1))
    const weekNo = Math.ceil((((date.getTime() - yearStart.getTime()) / 86400000) + 1) / 7)
    const year = date.getUTCFullYear()
    return `${year}-W${String(weekNo).padStart(2, '0')}`
  }

  async getAnalyticsSummaryForUser(userId: string, from?: string, to?: string) {
    const rows = await this.getAnalyticsForUser(userId)

    // Parse dates with proper time formatting
    const fromDate = from ? new Date(`${from}T00:00:00.000Z`) : null
    const toDate = to ? new Date(`${to}T23:59:59.999Z`) : null

    // Filter by workoutDt (not createdAt)
    const filtered = rows.filter((it) => {
      const dt = new Date(it.workoutDt)
      if (fromDate && dt < fromDate) return false
      if (toDate && dt > toDate) return false
      return true
    })

    const totals = {
      workouts: filtered.length,
      distanceKm: Number(filtered.reduce((s, r) => s + (r.distanceKm ?? 0), 0).toFixed(2)),
      durationMin: Number(filtered.reduce((s, r) => s + (r.durationMin ?? 0), 0).toFixed(2)),
      planCompliance: {
        planned: filtered.filter(r => r.planCompliance === 'planned').length,
        modified: filtered.filter(r => r.planCompliance === 'modified').length,
        unplanned: filtered.filter(r => r.planCompliance === 'unplanned').length,
      },
      fatigueFlags: filtered.filter(r => r.fatigueFlag === true).length,
    }

    const byWeekMap = new Map<string, { week: string; workouts: number; distanceKm: number; durationMin: number }>()
    for (const r of filtered) {
      const dt = new Date(r.workoutDt)
      const week = this.isoWeekKey(dt)
      const cur = byWeekMap.get(week) ?? { week, workouts: 0, distanceKm: 0, durationMin: 0 }
      cur.workouts += 1
      cur.distanceKm += (r.distanceKm ?? 0)
      cur.durationMin += (r.durationMin ?? 0)
      byWeekMap.set(week, cur)
    }

    const byWeek = Array.from(byWeekMap.values())
      .map(w => ({
        ...w,
        distanceKm: Number(w.distanceKm.toFixed(2)),
        durationMin: Number(w.durationMin.toFixed(2)),
      }))
      .sort((a, b) => a.week.localeCompare(b.week)) // rosnąco po tygodniach

    const byDayMap = new Map<string, { day: string; workouts: number; distanceKm: number; durationMin: number }>()
    for (const r of filtered) {
      const dt = new Date(r.workoutDt)
      const day = dt.toISOString().split('T')[0]! // YYYY-MM-DD
      const cur = byDayMap.get(day) ?? { day, workouts: 0, distanceKm: 0, durationMin: 0 }
      cur.workouts += 1
      cur.distanceKm += (r.distanceKm ?? 0)
      cur.durationMin += (r.durationMin ?? 0)
      byDayMap.set(day, cur)
    }

    const byDay = Array.from(byDayMap.values())
      .map(d => ({
        ...d,
        distanceKm: Number(d.distanceKm.toFixed(2)),
        durationMin: Number(d.durationMin.toFixed(2)),
      }))
      .sort((a, b) => a.day.localeCompare(b.day)) // rosnąco po dniu

    return { totals, byWeek, byDay }
  }
}

