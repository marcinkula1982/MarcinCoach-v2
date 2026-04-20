import { Inject, Injectable } from '@nestjs/common'
import { CLOCK, type Clock } from '../ai-rate-limit/clock'
import type { AiCacheStore } from './ai-cache.store'
import { AI_CACHE_STORE } from './ai-cache.store.token'

type Namespace = 'plan' | 'insights' | 'feedback'

@Injectable()
export class AiCacheService {
  constructor(
    @Inject(CLOCK) private readonly clock: Clock,
    @Inject(AI_CACHE_STORE) private readonly store: AiCacheStore,
  ) {}

  private getDayKeyUtc(): string {
    return this.clock.now().toISOString().split('T')[0]! // YYYY-MM-DD (UTC)
  }

  private buildKey(namespace: Namespace, userId: number, days: number): string {
    const dayKeyUtc = this.getDayKeyUtc()
    const stableKey = `days=${days}`
    return `${namespace}:${userId}:${dayKeyUtc}:${stableKey}`
  }

  async get<T>(namespace: Namespace, userId: number, days: number): Promise<T | null> {
    const key = this.buildKey(namespace, userId, days)
    return this.store.get<T>(key)
  }

  async set<T>(namespace: Namespace, userId: number, days: number, value: T): Promise<void> {
    const todayKeyUtc = this.getDayKeyUtc()

    // Best-effort cleanup of entries from other UTC days (in-memory store only).
    await this.store.cleanupOtherDays(todayKeyUtc)

    const key = this.buildKey(namespace, userId, days)
    const expireAt = Math.floor(new Date(this.resetAtIsoUtc()).getTime() / 1000)
    await this.store.set(key, value, expireAt)
  }

  resetAtIsoUtc(): string {
    const now = this.clock.now()
    const tomorrow = new Date(now)
    tomorrow.setUTCDate(tomorrow.getUTCDate() + 1)
    tomorrow.setUTCHours(0, 0, 0, 0)
    return tomorrow.toISOString()
  }
}
