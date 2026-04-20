/**
 * Storage backend for AiDailyRateLimitService.
 *
 * Two implementations ship in the box:
 *  - InMemoryRateLimitStore: default, per-process Map (not safe across restarts / multiple instances).
 *  - RedisRateLimitStore: production-grade, survives restarts and is shared across instances.
 *
 * Module wiring selects the Redis implementation when REDIS_URL is set; otherwise falls back to
 * in-memory so existing dev/test flows keep working.
 */

export interface RateLimitStore {
  /**
   * Atomically checks and increments the counter for a given key.
   * - When the projected value (current + 1) exceeds `limit`, the counter is NOT incremented
   *   and `{ allowed: false, used: current }` is returned.
   * - Otherwise the counter is incremented by 1 and `{ allowed: true, used: current + 1 }` is returned.
   *
   * `ttlSec` is the maximum time-to-live for the key (only enforced by the Redis backend).
   */
  consume(key: string, limit: number, ttlSec: number): Promise<{ allowed: boolean; used: number }>
}

/**
 * Default in-memory implementation.
 * Semantics match the legacy behaviour, but it is NOT safe across process restarts or multiple
 * backend instances — use RedisRateLimitStore for production.
 */
export class InMemoryRateLimitStore implements RateLimitStore {
  private readonly usedByKey = new Map<string, number>()
  private opsSinceCleanup = 0

  async consume(key: string, limit: number, _ttlSec: number): Promise<{ allowed: boolean; used: number }> {
    const current = this.usedByKey.get(key) ?? 0

    // Periodic cleanup — keep only keys from the last 3 UTC days.
    this.opsSinceCleanup++
    if (this.opsSinceCleanup % 200 === 0) {
      this.cleanupOlderThanDays(3)
    }

    if (current + 1 > limit) {
      return { allowed: false, used: current }
    }

    const next = current + 1
    this.usedByKey.set(key, next)
    return { allowed: true, used: next }
  }

  /**
   * Exposed for tests to reset state between cases.
   */
  _clear(): void {
    this.usedByKey.clear()
    this.opsSinceCleanup = 0
  }

  private cleanupOlderThanDays(keepDays: number): void {
    const now = new Date()
    const cutoffMs = Date.UTC(
      now.getUTCFullYear(),
      now.getUTCMonth(),
      now.getUTCDate() - (keepDays - 1),
      0,
      0,
      0,
      0,
    )
    for (const key of this.usedByKey.keys()) {
      const parts = key.split(':')
      const dayKey = parts[parts.length - 1]
      if (!dayKey) continue
      const [yStr, mStr, dStr] = dayKey.split('-')
      const y = Number(yStr)
      const m = Number(mStr)
      const da = Number(dStr)
      if (!Number.isFinite(y) || !Number.isFinite(m) || !Number.isFinite(da)) continue
      const dayMs = Date.UTC(y, m - 1, da, 0, 0, 0, 0)
      if (dayMs < cutoffMs) {
        this.usedByKey.delete(key)
      }
    }
  }
}

/**
 * Redis-backed implementation. Uses an atomic Lua script so that racing requests cannot
 * skip the limit check.
 *
 * The caller must supply a minimal client that matches the subset of `ioredis` used below.
 * We accept `any` to avoid pulling ioredis types into consumers that don't need Redis.
 */
export class RedisRateLimitStore implements RateLimitStore {
  // Lua: atomically check-and-increment with TTL.
  // KEYS[1] = rate-limit key
  // ARGV[1] = limit  ARGV[2] = ttl seconds
  // Returns {allowed(0|1), used}
  private static readonly LUA_SCRIPT = [
    'local current = redis.call("GET", KEYS[1])',
    'current = current and tonumber(current) or 0',
    'local limit = tonumber(ARGV[1])',
    'local ttl = tonumber(ARGV[2])',
    'if current + 1 > limit then',
    '  return {0, current}',
    'end',
    'local next = redis.call("INCR", KEYS[1])',
    'if next == 1 then',
    '  redis.call("EXPIRE", KEYS[1], ttl)',
    'end',
    'return {1, next}',
  ].join('\n')

  constructor(private readonly client: any) {}

  async consume(key: string, limit: number, ttlSec: number): Promise<{ allowed: boolean; used: number }> {
    const result = (await this.client.eval(
      RedisRateLimitStore.LUA_SCRIPT,
      1,
      key,
      String(limit),
      String(ttlSec),
    )) as [number, number]

    const allowed = Number(result?.[0]) === 1
    const used = Number(result?.[1] ?? 0)
    return { allowed, used }
  }
}
