import { HttpException } from '@nestjs/common'
import type { ExecutionContext } from '@nestjs/common'
import { AiDailyRateLimitGuard } from '../src/ai-rate-limit/ai-daily-rate-limit.guard'
import { AiDailyRateLimitService } from '../src/ai-rate-limit/ai-daily-rate-limit.service'
import type { Clock } from '../src/ai-rate-limit/clock'

function makeCtx(userId: number, url: string): ExecutionContext {
  const req: any = { authUser: { userId }, url }
  return {
    switchToHttp: () => ({
      getRequest: () => req,
    }),
  } as any
}

describe('AiDailyRateLimitGuard', () => {
  let now: Date
  let clock: Clock
  let service: AiDailyRateLimitService
  let guard: AiDailyRateLimitGuard

  beforeEach(() => {
    now = new Date('2025-01-01T10:00:00.000Z')
    clock = {
      now: () => now,
    }

    service = new AiDailyRateLimitService(clock as any)
    guard = new AiDailyRateLimitGuard(service)

    process.env.NODE_ENV = 'test'
    process.env.AI_DAILY_CALL_LIMIT_DEV = '3'
    delete process.env.AI_DAILY_CALL_LIMIT_PROD
  })

  it('sums limit across /ai/plan and /ai/insights and returns 429 after exceeding', () => {
    // 3 allowed total
    expect(guard.canActivate(makeCtx(1, '/ai/plan'))).toBe(true)
    expect(guard.canActivate(makeCtx(1, '/ai/insights'))).toBe(true)
    expect(guard.canActivate(makeCtx(1, '/ai/plan'))).toBe(true)

    try {
      guard.canActivate(makeCtx(1, '/ai/insights'))
      throw new Error('Expected rate limit error')
    } catch (e: any) {
      expect(e).toBeInstanceOf(HttpException)
      expect(e.getStatus()).toBe(429)
      expect(e.getResponse()).toEqual({
        statusCode: 429,
        message: 'AI daily limit exceeded',
        limit: 3,
        used: 3,
        resetAtIso: '2025-01-02T00:00:00.000Z',
      })
    }
  })

  it('resets on next UTC day (via injected clock)', () => {
    process.env.AI_DAILY_CALL_LIMIT_DEV = '2'

    expect(guard.canActivate(makeCtx(1, '/ai/plan'))).toBe(true)
    expect(guard.canActivate(makeCtx(1, '/ai/insights'))).toBe(true)

    // third same day -> blocked
    expect(() => guard.canActivate(makeCtx(1, '/ai/plan'))).toThrow(HttpException)

    // advance clock to next UTC day
    now = new Date('2025-01-02T00:00:01.000Z')
    expect(guard.canActivate(makeCtx(1, '/ai/plan'))).toBe(true)
  })
})


