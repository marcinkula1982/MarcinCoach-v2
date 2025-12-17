import { Test } from '@nestjs/testing'
import { AiDailyRateLimitService } from './ai-daily-rate-limit.service'
import { CLOCK } from './clock'
import type { Clock } from './clock'

describe('AiDailyRateLimitService', () => {
  let now: Date
  let clock: Clock
  let service: AiDailyRateLimitService

  beforeEach(async () => {
    now = new Date('2025-12-17T10:00:00.000Z')
    clock = { now: () => now }

    const mod = await Test.createTestingModule({
      providers: [AiDailyRateLimitService, { provide: CLOCK, useValue: clock }],
    }).compile()

    service = mod.get(AiDailyRateLimitService)
  })

  it('sums usage within the same UTC day', () => {
    const limit = 3

    expect(service.consume(123, limit)).toMatchObject({
      allowed: true,
      limit,
      used: 1,
      resetAtIso: '2025-12-18T00:00:00.000Z',
    })
    expect(service.consume(123, limit)).toMatchObject({ allowed: true, limit, used: 2 })
    expect(service.consume(123, limit)).toMatchObject({ allowed: true, limit, used: 3 })
  })

  it('returns exceeded state after limit is reached (allowed=false with correct limit/used)', () => {
    const limit = 3

    service.consume(123, limit)
    service.consume(123, limit)
    service.consume(123, limit)

    const res = service.consume(123, limit)
    expect(res).toMatchObject({
      allowed: false,
      limit,
      used: 3,
      resetAtIso: '2025-12-18T00:00:00.000Z',
    })
  })

  it('resets on the next UTC day (new dayKey => counter starts from 0 again)', () => {
    const limit = 2

    expect(service.consume(123, limit)).toMatchObject({ allowed: true, used: 1 })
    expect(service.consume(123, limit)).toMatchObject({ allowed: true, used: 2 })
    expect(service.consume(123, limit)).toMatchObject({ allowed: false, used: 2 })

    // Advance fake clock to the next UTC day.
    now = new Date('2025-12-18T00:00:01.000Z')

    expect(service.consume(123, limit)).toMatchObject({
      allowed: true,
      used: 1,
      resetAtIso: '2025-12-19T00:00:00.000Z',
    })
  })
})


