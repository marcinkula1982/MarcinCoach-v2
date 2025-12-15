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
import type { WorkoutSummary } from '../types/workout.types'
import type { IntensityBuckets } from '../types/metrics.types'

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

  private safeReadSummary(raw: string | null | undefined) {
    return this.safeJsonParse(raw) ?? {}
  }

  private safeReadMeta(raw: string | null | undefined) {
    return this.safeJsonParse(raw) ?? undefined
  }

  /**
   * Intensity z tempa, ale user-agnostic:
   * dzielimy odcinki na 5 bucketów wg ważonych kwantyli (czasem) tempa w danym treningu.
   * z1 = najwolniej, z5 = najszybciej (relatywnie w ramach tej aktywności).
   */
  private computeIntensityBucketsFromTrackpoints(
    trackpoints: Array<{ time: string; distanceMeters: number | null }>,
  ): IntensityBuckets {
    const buckets: IntensityBuckets = { z1Sec: 0, z2Sec: 0, z3Sec: 0, z4Sec: 0, z5Sec: 0 }

    const toSec = (iso: string) => Math.floor(new Date(iso).getTime() / 1000)

    // Zbieramy segmenty: paceSecPerKm + czas segmentu dtSec (waga)
    const segs: Array<{ pace: number; dt: number }> = []

    for (let i = 1; i < trackpoints.length; i++) {
      const prev = trackpoints[i - 1]
      const cur = trackpoints[i]

      if (!prev?.time || !cur?.time) continue
      const t0 = toSec(prev.time)
      const t1 = toSec(cur.time)
      const dt = t1 - t0
      if (dt <= 0 || dt > 30) continue // filtr na dziury/artefakty

      const d0 = prev.distanceMeters
      const d1 = cur.distanceMeters
      if (d0 == null || d1 == null) continue

      const dd = d1 - d0
      if (dd <= 0) continue

      const pace = dt / (dd / 1000) // sec/km
      if (!Number.isFinite(pace) || pace <= 0) continue

      segs.push({ pace, dt })
    }

    if (segs.length === 0) return buckets

    // ważony kwantyl (waga = dt)
    const weightedQuantile = (q: number) => {
      const arr = [...segs].sort((a, b) => a.pace - b.pace) // rosnąco = szybciej -> wolniej? (mniejsze pace = szybciej)
      // UWAGA: pace mniejsze = szybciej. My chcemy z1=wolniej, więc odwrócimy klasyfikację niżej.
      const total = arr.reduce((s, x) => s + x.dt, 0)
      const target = total * q
      let acc = 0
      for (const x of arr) {
        acc += x.dt
        if (acc >= target) return x.pace
      }
      return arr[arr.length - 1]!.pace
    }

    // progi na tempie (sec/km), ale z definicji statystyczne dla tego treningu
    // q20..q80 liczone na rozkładzie "szybkości" (pace), potem mapujemy na z5..z1
    const q20 = weightedQuantile(0.2)
    const q40 = weightedQuantile(0.4)
    const q60 = weightedQuantile(0.6)
    const q80 = weightedQuantile(0.8)

    for (const s of segs) {
      // pace mniejsze = szybciej:
      // najszybciej -> z5, najwolniej -> z1
      if (s.pace <= q20) buckets.z5Sec += s.dt
      else if (s.pace <= q40) buckets.z4Sec += s.dt
      else if (s.pace <= q60) buckets.z3Sec += s.dt
      else if (s.pace <= q80) buckets.z2Sec += s.dt
      else buckets.z1Sec += s.dt
    }

    return buckets
  }

  async create(userId: number, username: string | undefined, dto: SaveWorkoutDto) {
    if (!userId) {
      throw new BadRequestException('Missing user from session')
    }

    // anti-duplicate (same user + same tcx start time + same duration + same distance)
    // NOTE: Race condition possible - two parallel requests may both pass this check.
    const startTimeIso = dto.summary?.startTimeIso ?? null
    const durationSec =
      dto.summary?.trimmed?.durationSec ?? dto.summary?.original?.durationSec ?? null
    const distanceM =
      dto.summary?.trimmed?.distanceM ?? dto.summary?.original?.distanceM ?? null

    if (startTimeIso && durationSec != null && distanceM != null) {
      const recent = await this.prisma.workout.findMany({
        where: { userId },
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

    // dla ręcznych zapisów używamy prostego, unikalnego klucza (brak deduplikacji)
    const dedupeKey = `MANUAL:${Date.now().toString(36)}:${Math.random().toString(36).slice(2, 8)}`

    const workout = await this.prisma.workout.create({
      data: {
        userId,
        action: dto.action,
        kind: dto.kind,
        summary: JSON.stringify(dto.summary),
        raceMeta: dto.raceMeta ? JSON.stringify(dto.raceMeta) : null,
        workoutMeta: dto.workoutMeta ? JSON.stringify(dto.workoutMeta) : null,
        tcxRaw: dto.tcxRaw,
        dedupeKey,
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
      userId,
      action: workout.action,
      kind: workout.kind,
      summary: this.safeJsonParse(workout.summary) ?? {},
      raceMeta: this.safeJsonParse(workout.raceMeta) ?? undefined,
      workoutMeta: this.safeJsonParse(workout.workoutMeta) ?? undefined,
      createdAt: workout.createdAt,
    }
  }

  async findAllForUser(userId: number) {
    const workouts = await this.prisma.workout.findMany({
      where: { userId },
      select: {
        id: true,
        action: true,
        kind: true,
        summary: true,
        raceMeta: true,
        workoutMeta: true,
        createdAt: true,
      },
      orderBy: { createdAt: 'desc' },
    })

    return workouts.map((w) => ({
      id: w.id,
      userId,
      action: w.action,
      kind: w.kind,
      summary: this.safeJsonParse(w.summary) ?? {},
      raceMeta: this.safeJsonParse(w.raceMeta) ?? undefined,
      workoutMeta: this.safeJsonParse(w.workoutMeta) ?? undefined,
      createdAt: w.createdAt,
    }))
  }

  async uploadTcxFile(file: Express.Multer.File, userId: number, username: string | undefined) {
    if (!file || !file.buffer) {
      throw new BadRequestException('Brak pliku do zapisu')
    }

    const rawTcx = file.buffer.toString('utf-8')
    if (!rawTcx.trim()) {
      throw new BadRequestException('Plik jest pusty')
    }

    const parsed = parseTcx(rawTcx)
    const metrics = computeMetrics(parsed.trackpoints)

    const intensity = this.computeIntensityBucketsFromTrackpoints(
      parsed.trackpoints.map((tp) => ({
        time: tp.time,
        distanceMeters: tp.distanceMeters ?? null,
      })),
    )

    const summary: WorkoutSummary = {
      fileName: file.originalname ?? 'upload.tcx',
      startTimeIso: parsed.startTimeIso,
      original: metrics,
      trimmed: metrics,
      intensity,
      totalPoints: parsed.trackpoints.length,
      selectedPoints: parsed.trackpoints.length,
    }

    const dto: ImportWorkoutDto = {
      source: 'MANUAL_UPLOAD',
      sourceActivityId: null,
      sourceUserId: null,
      tcxRaw: rawTcx,
      fitRaw: null,
      summary,
      workoutMeta: null,
      startTimeIso: summary.startTimeIso ?? null,
    }

    // JEDEN punkt prawdy: importWorkout (dedupe + zapis)
    return this.importWorkout(userId, username, dto)
  }

  async importWorkout(userId: number, username: string | undefined, dto: ImportWorkoutDto) {
    if (!userId) {
      throw new BadRequestException('Missing user from session')
    }

    // Walidacja: co najmniej jedno z tcxRaw/fitRaw lub sourceActivityId
    const hasRaw = Boolean(dto.tcxRaw) || Boolean(dto.fitRaw)
    const hasStrongId = Boolean(dto.sourceActivityId)

    if (!hasRaw && !hasStrongId) {
      throw new BadRequestException('Either tcxRaw/fitRaw or sourceActivityId is required')
    }

    const {
      source: normalizedSource,
      sourceActivityId: normalizedSourceActivityId,
      dedupeKey,
    } = this.buildDedupeKey({
      source: dto.source,
      sourceActivityId: dto.sourceActivityId ?? null,
      summary: dto.summary,
      fallbackStartIso: dto.startTimeIso,
    })

    const workoutData = {
      userId,
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

    try {
      const created = await this.prisma.workout.create({
        data: { ...workoutData, dedupeKey },
      })

      return {
        id: created.id,
        userId,
        action: created.action,
        kind: created.kind,
        summary: this.safeJsonParse(created.summary) ?? {},
        raceMeta: this.safeJsonParse(created.raceMeta) ?? undefined,
        workoutMeta: this.safeJsonParse(created.workoutMeta) ?? undefined,
        createdAt: created.createdAt,
      }
    } catch (e: any) {
      // P2002 = unique constraint failed on (userId, dedupeKey) -> zwracamy istniejący rekord (idempotent)
      if (e?.code === 'P2002') {
        const existing = await this.prisma.workout.findFirst({
          where: { userId, dedupeKey },
        })
        if (existing) {
          return {
            id: existing.id,
            userId,
            action: existing.action,
            kind: existing.kind,
            summary: this.safeReadSummary(existing.summary),
            raceMeta: this.safeReadMeta(existing.raceMeta),
            workoutMeta: this.safeReadMeta(existing.workoutMeta),
            createdAt: existing.createdAt,
          }
        }
      }
      throw e
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
        tcxRaw: true,
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
      tcxRaw: includeRaw ? w.tcxRaw : undefined,
    }
  }

  async findOneForUser(id: number, userId: number, includeRaw = false) {
    const workout = await this.prisma.workout.findFirst({
      where: {
        id,
        userId,
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
        tcxRaw: true,
      },
    })

    if (!workout) {
      throw new NotFoundException('Workout not found')
    }

    return {
      id: workout.id,
      userId,
      action: workout.action,
      kind: workout.kind,
      summary: this.safeJsonParse(workout.summary) ?? {},
      raceMeta: this.safeJsonParse(workout.raceMeta) ?? undefined,
      workoutMeta: this.safeJsonParse(workout.workoutMeta) ?? undefined,
      createdAt: workout.createdAt,
      updatedAt: workout.updatedAt,
      tcxRaw: includeRaw ? workout.tcxRaw : undefined,
    }
  }

  async updateMeta(
    id: number,
    workoutMeta: UpdateWorkoutMetaDto['workoutMeta'],
    userId: number,
  ) {
    const workout = await this.prisma.workout.findFirst({
      where: {
        id,
        userId,
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

  async deleteByIdForUser(id: number, userId: number) {
    const workout = await this.prisma.workout.findFirst({
      where: {
        id,
        userId,
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

  async getAnalyticsForUser(userId: number) {
    const round = (v: number | null, d = 2) =>
      typeof v === 'number' && Number.isFinite(v) ? Math.round(v * 10 ** d) / 10 ** d : null

    const workouts = await this.prisma.workout.findMany({
      where: { userId },
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

      const workoutDt = summary.startTimeIso ? new Date(summary.startTimeIso) : w.createdAt

      const distanceM = summary.trimmed?.distanceM ?? summary.original?.distanceM ?? null
      const distanceKm = distanceM != null ? distanceM / 1000.0 : null

      const durationSec = summary.trimmed?.durationSec ?? summary.original?.durationSec ?? null
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
    const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()))
    const day = date.getUTCDay() || 7
    date.setUTCDate(date.getUTCDate() + 4 - day)
    const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1))
    const weekNo = Math.ceil((((date.getTime() - yearStart.getTime()) / 86400000) + 1) / 7)
    const year = date.getUTCFullYear()
    return `${year}-W${String(weekNo).padStart(2, '0')}`
  }

  async getAnalyticsSummaryForUser(userId: number, from?: string, to?: string) {
    const rows = await this.getAnalyticsForUser(userId)

    const fromDate = from ? new Date(`${from}T00:00:00.000Z`) : null
    const toDate = to ? new Date(`${to}T23:59:59.999Z`) : null

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
        planned: filtered.filter((r) => r.planCompliance === 'planned').length,
        modified: filtered.filter((r) => r.planCompliance === 'modified').length,
        unplanned: filtered.filter((r) => r.planCompliance === 'unplanned').length,
      },
      fatigueFlags: filtered.filter((r) => r.fatigueFlag === true).length,
    }

    const byWeekMap = new Map<string, { week: string; workouts: number; distanceKm: number; durationMin: number }>()
    for (const r of filtered) {
      const dt = new Date(r.workoutDt)
      const week = this.isoWeekKey(dt)
      const cur = byWeekMap.get(week) ?? { week, workouts: 0, distanceKm: 0, durationMin: 0 }
      cur.workouts += 1
      cur.distanceKm += r.distanceKm ?? 0
      cur.durationMin += r.durationMin ?? 0
      byWeekMap.set(week, cur)
    }

    const byWeek = Array.from(byWeekMap.values())
      .map((w) => ({
        ...w,
        distanceKm: Number(w.distanceKm.toFixed(2)),
        durationMin: Number(w.durationMin.toFixed(2)),
      }))
      .sort((a, b) => a.week.localeCompare(b.week))

    const byDayMap = new Map<string, { day: string; workouts: number; distanceKm: number; durationMin: number }>()
    for (const r of filtered) {
      const dt = new Date(r.workoutDt)
      const day = dt.toISOString().split('T')[0]!
      const cur = byDayMap.get(day) ?? { day, workouts: 0, distanceKm: 0, durationMin: 0 }
      cur.workouts += 1
      cur.distanceKm += r.distanceKm ?? 0
      cur.durationMin += r.durationMin ?? 0
      byDayMap.set(day, cur)
    }

    const byDay = Array.from(byDayMap.values())
      .map((d) => ({
        ...d,
        distanceKm: Number(d.distanceKm.toFixed(2)),
        durationMin: Number(d.durationMin.toFixed(2)),
      }))
      .sort((a, b) => a.day.localeCompare(b.day))

    return { totals, byWeek, byDay }
  }

  // ---------- Dedupe helpers ----------

  private normalizeSource(source?: string | null): string {
    if (!source) return 'MANUAL_UPLOAD'
    const trimmed = source.trim().toUpperCase()
    return trimmed || 'MANUAL_UPLOAD'
  }

  private normalizeId(value?: string | null): string | null {
    if (!value) return null
    const trimmed = value.trim()
    return trimmed.length > 0 ? trimmed : null
  }

  private buildDedupeKey(params: {
    source?: string | null
    sourceActivityId?: string | null
    summary: WorkoutSummary
    fallbackStartIso?: string | null
  }): { source: string; sourceActivityId: string | null; dedupeKey: string } {
    const sourceNorm = this.normalizeSource(params.source)
    const idNorm = this.normalizeId(params.sourceActivityId ?? null)

    if (idNorm) {
      return {
        source: sourceNorm,
        sourceActivityId: idNorm,
        dedupeKey: `${sourceNorm}:${idNorm}`,
      }
    }

    const summary = params.summary
    const workoutDtIso =
      summary.startTimeIso ?? params.fallbackStartIso ?? new Date().toISOString()

    const durationSec = summary.trimmed?.durationSec ?? summary.original?.durationSec ?? 0
    const distanceM = summary.trimmed?.distanceM ?? summary.original?.distanceM ?? 0

    const durationSecNorm = Math.round(durationSec / 5) * 5
    const distanceMNorm = Math.round(distanceM / 10) * 10

    return {
      source: sourceNorm,
      sourceActivityId: idNorm,
      dedupeKey: `${sourceNorm}:t=${workoutDtIso}:d=${durationSecNorm}:m=${distanceMNorm}`,
    }
  }
}
