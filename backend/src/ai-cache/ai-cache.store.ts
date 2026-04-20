/**
 * Storage backend for AiCacheService.
 *
 * Same pattern as ai-rate-limit.store:
 *  - InMemoryAiCacheStore: default, per-process Map (single instance).
 *  - RedisAiCacheStore: production-grade, shared across instances, TTL-backed.
 *
 * Module wiring selects the Redis implementation when REDIS_URL is set.
 */

export interface AiCacheStore {
  get<T>(key: string): Promise<T | null>
  /**
   * `expireAtEpochSec` is the absolute expiration time (unix seconds, UTC).
   * In-memory store uses it only for housekeeping on `set`; Redis store uses EXPIREAT.
   */
  set<T>(key: string, value: T, expireAtEpochSec: number): Promise<void>
  /**
   * Deletes every key whose stored `dayKey` differs from the supplied one.
   * Implemented best-effort; Redis relies on TTLs and the call is a no-op there.
   */
  cleanupOtherDays(currentDayKey: string): Promise<void>
}

type MemoryEntry<T> = { value: T; dayKey: string }

export class InMemoryAiCacheStore implements AiCacheStore {
  private readonly map = new Map<string, MemoryEntry<any>>()

  async get<T>(key: string): Promise<T | null> {
    const entry = this.map.get(key) as MemoryEntry<T> | undefined
    return entry ? entry.value : null
  }

  async set<T>(key: string, value: T, _expireAtEpochSec: number): Promise<void> {
    // Extract dayKey from the built key (shape: `${namespace}:${userId}:${dayKey}:${stableKey}`)
    const parts = key.split(':')
    const dayKey = parts[2] ?? ''
    this.map.set(key, { value, dayKey })
  }

  async cleanupOtherDays(currentDayKey: string): Promise<void> {
    for (const [k, entry] of this.map) {
      if (entry.dayKey !== currentDayKey) {
        this.map.delete(k)
      }
    }
  }

  /** Exposed for tests. */
  _clear(): void {
    this.map.clear()
  }
}

export class RedisAiCacheStore implements AiCacheStore {
  constructor(private readonly client: any) {}

  async get<T>(key: string): Promise<T | null> {
    const raw = await this.client.get(key)
    if (raw == null) return null
    try {
      return JSON.parse(raw) as T
    } catch {
      return null
    }
  }

  async set<T>(key: string, value: T, expireAtEpochSec: number): Promise<void> {
    const payload = JSON.stringify(value)
    // Store then pin the exact expiration to the next UTC midnight.
    await this.client.set(key, payload)
    try {
      await this.client.expireat(key, expireAtEpochSec)
    } catch {
      // Some Redis clients expose EXPIREAT as `expireat`; others as `expireAt`. Try fallback.
      if (typeof this.client.expireAt === 'function') {
        await this.client.expireAt(key, expireAtEpochSec)
      }
    }
  }

  async cleanupOtherDays(_currentDayKey: string): Promise<void> {
    // No-op: Redis handles expiry via TTL set on write.
  }
}
