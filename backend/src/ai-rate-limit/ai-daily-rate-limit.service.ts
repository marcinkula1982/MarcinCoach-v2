import { Inject, Injectable } from '@nestjs/common'
import type { Clock } from './clock'
import { CLOCK } from './clock'
import type { RateLimitStore } from './ai-rate-limit.store'
import { RATE_LIMIT_STORE } from './ai-rate-limit.store.token'

type ConsumeResult = {
  allowed: boolean
  limit: number
  used: number
  resetAtIso: string
  dayKeyUtc: string
}

@Injectable()
export class AiDailyRateLimitService {
  constructor(
    @Inject(CLOCK) private readonly clock: Clock,
    @Inject(RATE_LIMIT_STORE) private readonly store: RateLimitStore,
  ) {}

  async consume(userId: number, limit: number): Promise<ConsumeResult> {
    const now = this.clock.now()
    const dayKeyUtc = this.dayKeyUtc(now)
    const resetAtIso = this.nextUtcMidnightIso(now)

    const safeLimit = Number.isFinite(limit) && limit > 0 ? Math.floor(limit) : 1
    const key = `ai-rate-limit:${userId}:${dayKeyUtc}`

    // TTL: 48h — more than enough to survive past midnight; resetAtIso is the hard contract.
    const ttlSec = 48 * 60 * 60

    const { allowed, used } = await this.store.consume(key, safeLimit, ttlSec)
    return { allowed, limit: safeLimit, used, resetAtIso, dayKeyUtc }
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
}
