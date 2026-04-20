import { Test } from '@nestjs/testing'
import { AiCacheService } from './ai-cache.service'
import { CLOCK } from '../ai-rate-limit/clock'
import type { Clock } from '../ai-rate-limit/clock'
import { AI_CACHE_STORE } from './ai-cache.store.token'
import { InMemoryAiCacheStore, RedisAiCacheStore } from './ai-cache.store'

describe('AiCacheService', () => {
  let now: Date
  let clock: Clock
  let service: AiCacheService

  beforeEach(async () => {
    now = new Date('2025-12-17T10:00:00.000Z')
    clock = { now: () => now }

    const mod = await Test.createTestingModule({
      providers: [
        AiCacheService,
        { provide: CLOCK, useValue: clock },
        { provide: AI_CACHE_STORE, useValue: new InMemoryAiCacheStore() },
      ],
    }).compile()

    service = mod.get(AiCacheService)
  })

  it('returns null for non-existent cache entry', async () => {
    const result = await service.get('plan', 123, 28)
    expect(result).toBeNull()
  })

  it('stores and retrieves cache entry for plan', async () => {
    const payload = { test: 'data', windowDays: 28 }
    await service.set('plan', 123, 28, payload)

    const result = await service.get('plan', 123, 28)
    expect(result).toMatchObject(payload)
  })

  it('stores and retrieves cache entry for insights', async () => {
    const payload = { summary: ['test'], windowDays: 28 }
    await service.set('insights', 456, 28, payload)

    const result = await service.get('insights', 456, 28)
    expect(result).toMatchObject(payload)
  })

  it('returns null for different userId', async () => {
    await service.set('plan', 123, 28, { test: 'data' })

    const result = await service.get('plan', 999, 28)
    expect(result).toBeNull()
  })

  it('returns null for different days', async () => {
    await service.set('plan', 123, 28, { test: 'data' })

    const result = await service.get('plan', 123, 14)
    expect(result).toBeNull()
  })

  it('returns null for different namespace', async () => {
    await service.set('plan', 123, 28, { test: 'data' })

    const result = await service.get('insights', 123, 28)
    expect(result).toBeNull()
  })

  it('overwrites existing cache entry', async () => {
    await service.set('plan', 123, 28, { test: 'old' })
    await service.set('plan', 123, 28, { test: 'new' })

    const result = await service.get('plan', 123, 28)
    expect(result).toMatchObject({ test: 'new' })
  })

  it('cleans up entries from other UTC days on set', async () => {
    // Set entry for today
    await service.set('plan', 123, 28, { test: 'today' })

    // Advance clock to next UTC day
    now = new Date('2025-12-18T00:00:01.000Z')

    // Set new entry - should clean up old one
    await service.set('plan', 123, 28, { test: 'tomorrow' })

    // New entry should be present
    expect(await service.get('plan', 123, 28)).toMatchObject({ test: 'tomorrow' })

    // Rewind clock - previous-day entry should no longer exist
    now = new Date('2025-12-17T10:00:00.000Z')
    expect(await service.get('plan', 123, 28)).toBeNull()
  })

  it('keeps entries from same UTC day', async () => {
    await service.set('plan', 123, 28, { test: 'plan1' })
    await service.set('insights', 123, 28, { test: 'insights1' })
    await service.set('plan', 456, 28, { test: 'plan2' })

    expect(await service.get('plan', 123, 28)).toMatchObject({ test: 'plan1' })
    expect(await service.get('insights', 123, 28)).toMatchObject({ test: 'insights1' })
    expect(await service.get('plan', 456, 28)).toMatchObject({ test: 'plan2' })
  })

  it('returns correct resetAtIsoUtc for next UTC midnight', () => {
    now = new Date('2025-12-17T10:00:00.000Z')
    const resetAt = service.resetAtIsoUtc()
    expect(resetAt).toBe('2025-12-18T00:00:00.000Z')
  })

  it('returns correct resetAtIsoUtc at UTC midnight boundary', () => {
    now = new Date('2025-12-17T23:59:59.999Z')
    const resetAt = service.resetAtIsoUtc()
    expect(resetAt).toBe('2025-12-18T00:00:00.000Z')
  })

  it('returns correct resetAtIsoUtc after UTC midnight', () => {
    now = new Date('2025-12-18T00:00:01.000Z')
    const resetAt = service.resetAtIsoUtc()
    expect(resetAt).toBe('2025-12-19T00:00:00.000Z')
  })

  it('handles month boundary correctly for resetAtIsoUtc', () => {
    now = new Date('2025-12-31T10:00:00.000Z')
    const resetAt = service.resetAtIsoUtc()
    expect(resetAt).toBe('2026-01-01T00:00:00.000Z')
  })
})

describe('RedisAiCacheStore', () => {
  it('serializes payload on set and parses payload on get', async () => {
    const kv = new Map<string, string>()
    const expireAtCalls: Array<{ key: string; ts: number }> = []
    const client = {
      set: jest.fn(async (key: string, payload: string) => {
        kv.set(key, payload)
      }),
      get: jest.fn(async (key: string) => kv.get(key) ?? null),
      expireat: jest.fn(async (key: string, ts: number) => {
        expireAtCalls.push({ key, ts })
      }),
    }
    const store = new RedisAiCacheStore(client)

    await store.set('plan:1:2025-12-17:days=28', { ok: true }, 1766016000)
    const result = await store.get<{ ok: boolean }>('plan:1:2025-12-17:days=28')

    expect(result).toEqual({ ok: true })
    expect(expireAtCalls).toEqual([{ key: 'plan:1:2025-12-17:days=28', ts: 1766016000 }])
  })

  it('falls back to expireAt when expireat is unavailable', async () => {
    const client = {
      set: jest.fn(async () => {}),
      get: jest.fn(async () => null),
      expireat: jest.fn(async () => {
        throw new Error('expireat not available')
      }),
      expireAt: jest.fn(async () => {}),
    }
    const store = new RedisAiCacheStore(client)

    await store.set('insights:2:2025-12-17:days=14', { score: 1 }, 1766016000)

    expect(client.expireAt).toHaveBeenCalledWith('insights:2:2025-12-17:days=14', 1766016000)
  })
})
