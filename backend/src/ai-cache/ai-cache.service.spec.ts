import { Test } from '@nestjs/testing'
import { AiCacheService } from './ai-cache.service'
import { CLOCK } from '../ai-rate-limit/clock'
import type { Clock } from '../ai-rate-limit/clock'

describe('AiCacheService', () => {
  let now: Date
  let clock: Clock
  let service: AiCacheService

  beforeEach(async () => {
    now = new Date('2025-12-17T10:00:00.000Z')
    clock = { now: () => now }

    const mod = await Test.createTestingModule({
      providers: [AiCacheService, { provide: CLOCK, useValue: clock }],
    }).compile()

    service = mod.get(AiCacheService)
  })

  it('returns null for non-existent cache entry', () => {
    const result = service.get('plan', 123, 28)
    expect(result).toBeNull()
  })

  it('stores and retrieves cache entry for plan', () => {
    const payload = { test: 'data', windowDays: 28 }
    service.set('plan', 123, 28, payload)

    const result = service.get('plan', 123, 28)
    expect(result).toMatchObject({
      payload,
      cache: 'hit',
    })
  })

  it('stores and retrieves cache entry for insights', () => {
    const payload = { summary: ['test'], windowDays: 28 }
    service.set('insights', 456, 28, payload)

    const result = service.get('insights', 456, 28)
    expect(result).toMatchObject({
      payload,
      cache: 'hit',
    })
  })

  it('returns null for different userId', () => {
    service.set('plan', 123, 28, { test: 'data' })

    const result = service.get('plan', 999, 28)
    expect(result).toBeNull()
  })

  it('returns null for different days', () => {
    service.set('plan', 123, 28, { test: 'data' })

    const result = service.get('plan', 123, 14)
    expect(result).toBeNull()
  })

  it('returns null for different namespace', () => {
    service.set('plan', 123, 28, { test: 'data' })

    const result = service.get('insights', 123, 28)
    expect(result).toBeNull()
  })

  it('overwrites existing cache entry', () => {
    service.set('plan', 123, 28, { test: 'old' })
    service.set('plan', 123, 28, { test: 'new' })

    const result = service.get('plan', 123, 28)
    expect(result?.payload).toMatchObject({ test: 'new' })
  })

  it('cleans up entries from other UTC days on set', () => {
    // Set entry for today
    service.set('plan', 123, 28, { test: 'today' })

    // Advance clock to next UTC day
    now = new Date('2025-12-18T00:00:01.000Z')

    // Set new entry - should clean up old one
    service.set('plan', 123, 28, { test: 'tomorrow' })

    // Old entry should be gone
    const oldResult = service.get('plan', 123, 28)
    expect(oldResult?.payload).toMatchObject({ test: 'tomorrow' })

    // Verify only one entry exists (the new one)
    now = new Date('2025-12-17T10:00:00.000Z')
    const resultBeforeCleanup = service.get('plan', 123, 28)
    // After cleanup, old day's entry should not exist
    expect(resultBeforeCleanup).toBeNull()
  })

  it('keeps entries from same UTC day', () => {
    // Set multiple entries for same day
    service.set('plan', 123, 28, { test: 'plan1' })
    service.set('insights', 123, 28, { test: 'insights1' })
    service.set('plan', 456, 28, { test: 'plan2' })

    // All should still exist
    expect(service.get('plan', 123, 28)?.payload).toMatchObject({ test: 'plan1' })
    expect(service.get('insights', 123, 28)?.payload).toMatchObject({ test: 'insights1' })
    expect(service.get('plan', 456, 28)?.payload).toMatchObject({ test: 'plan2' })
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

