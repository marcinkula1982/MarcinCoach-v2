import { Inject, Injectable } from '@nestjs/common'
import type { Clock } from './clock'
import { CLOCK } from './clock'

type ConsumeResult = {
  allowed: boolean
  limit: number
  used: number
  resetAtIso: string
  dayKeyUtc: string
}

@Injectable()
export class AiDailyRateLimitService {
  /**
   * Map key: `${userId}:${YYYY-MM-DD}` (UTC)
   * Value: used calls for that day
   */
  private readonly usedByUserDay = new Map<string, number>()

  private opsSinceCleanup = 0

  constructor(@Inject(CLOCK) private readonly clock: Clock) {}

  consume(userId: number, limit: number): ConsumeResult {
    const now = this.clock.now()
    const dayKeyUtc = this.dayKeyUtc(now)
    const resetAtIso = this.nextUtcMidnightIso(now)

    const safeLimit = Number.isFinite(limit) && limit > 0 ? Math.floor(limit) : 1
    const key = `${userId}:${dayKeyUtc}`

    const used = this.usedByUserDay.get(key) ?? 0

    // Periodic cleanup (keep a small rolling window; Map is in-memory)
    this.opsSinceCleanup++
    if (this.opsSinceCleanup % 200 === 0) {
      this.cleanupOlderThanDays(now, 3)
    }

    if (used + 1 > safeLimit) {
      return { allowed: false, limit: safeLimit, used, resetAtIso, dayKeyUtc }
    }

    const nextUsed = used + 1
    this.usedByUserDay.set(key, nextUsed)
    return { allowed: true, limit: safeLimit, used: nextUsed, resetAtIso, dayKeyUtc }
  }

  private dayKeyUtc(d: Date): string {
    const yyyy = d.getUTCFullYear()
    const mm = String(d.getUTCMonth() + 1).padStart(2, '0')
    const dd = String(d.getUTCDate()).padStart(2, '0')
    return `${yyyy}-${mm}-${dd}`
  }

  private nextUtcMidnightIso(d: Date): string {
    const yyyy = d.getUTCFullYear()
    const mm = d.getUTCMonth()
    const dd = d.getUTCDate()
    const nextMidnight = new Date(Date.UTC(yyyy, mm, dd + 1, 0, 0, 0, 0))
    return nextMidnight.toISOString()
  }

  private cleanupOlderThanDays(now: Date, keepDays: number) {
    const yyyy = now.getUTCFullYear()
    const mm = now.getUTCMonth()
    const dd = now.getUTCDate()
    const cutoffMs = Date.UTC(yyyy, mm, dd - (keepDays - 1), 0, 0, 0, 0)

    for (const [key] of this.usedByUserDay) {
      const parts = key.split(':')
      const dayKey = parts[1]
      if (!dayKey) continue

      const [yStr, mStr, dStr] = dayKey.split('-')
      const y = Number(yStr)
      const m = Number(mStr)
      const da = Number(dStr)
      if (!Number.isFinite(y) || !Number.isFinite(m) || !Number.isFinite(da)) continue

      const dayMs = Date.UTC(y, m - 1, da, 0, 0, 0, 0)
      if (dayMs < cutoffMs) {
        this.usedByUserDay.delete(key)
      }
    }
  }
}










