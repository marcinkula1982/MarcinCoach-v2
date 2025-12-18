import { Inject, Injectable } from '@nestjs/common'
import { CLOCK, type Clock } from '../ai-rate-limit/clock'

type CacheEntry<T> = {
  value: T
  createdAtIso: string
}

type Namespace = 'plan' | 'insights' | 'feedback'

@Injectable()
export class AiCacheService {
  private cache = new Map<string, CacheEntry<any>>()

  constructor(@Inject(CLOCK) private readonly clock: Clock) {}

  private getDayKeyUtc(): string {
    return this.clock.now().toISOString().split('T')[0]! // YYYY-MM-DD (UTC)
  }

  private buildKey(namespace: Namespace, userId: number, days: number): string {
    const dayKeyUtc = this.getDayKeyUtc()
    const stableKey = `days=${days}`
    return `${namespace}:${userId}:${dayKeyUtc}:${stableKey}`
  }

  get<T>(namespace: Namespace, userId: number, days: number): { payload: T; cache: 'hit' } | null {
    const key = this.buildKey(namespace, userId, days)
    const entry = this.cache.get(key) as CacheEntry<T> | undefined
    if (!entry) return null
    return { payload: entry.value, cache: 'hit' }
  }

  set<T>(namespace: Namespace, userId: number, days: number, value: T): void {
    const todayKeyUtc = this.getDayKeyUtc()

    // cleanup old UTC days
    for (const key of this.cache.keys()) {
      const parts = key.split(':')
      const dayKey = parts[2]
      if (dayKey && dayKey !== todayKeyUtc) {
        this.cache.delete(key)
      }
    }

    const key = this.buildKey(namespace, userId, days)
    this.cache.set(key, {
      value,
      createdAtIso: this.clock.now().toISOString(),
    })
  }

  resetAtIsoUtc(): string {
    const now = this.clock.now()
    const tomorrow = new Date(now)
    tomorrow.setUTCDate(tomorrow.getUTCDate() + 1)
    tomorrow.setUTCHours(0, 0, 0, 0)
    return tomorrow.toISOString()
  }
}
