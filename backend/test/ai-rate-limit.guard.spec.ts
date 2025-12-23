import { ExecutionContext, HttpException } from '@nestjs/common'
import { AiDailyRateLimitGuard } from '../src/ai-rate-limit/ai-daily-rate-limit.guard'
import { AiDailyRateLimitService } from '../src/ai-rate-limit/ai-daily-rate-limit.service'

function mockCtx(userId?: number): ExecutionContext {
  return {
    switchToHttp: () => ({
      getRequest: () => ({
        authUser: userId ? { userId } : undefined,
      }),
    }),
  } as any
}

describe('AiDailyRateLimitGuard', () => {
  let service: AiDailyRateLimitService
  let guard: AiDailyRateLimitGuard

  beforeEach(() => {
    service = new AiDailyRateLimitService({ now: () => new Date('2025-01-01T10:00:00Z') })
    guard = new AiDailyRateLimitGuard(service)
  })

  afterEach(() => {
    delete process.env.AI_DAILY_CALL_LIMIT_DEV
    delete process.env.AI_DAILY_CALL_LIMIT_PROD
    delete process.env.NODE_ENV
  })

  it('returns 429 when limit = 0 (AI disabled)', () => {
    process.env.AI_DAILY_CALL_LIMIT_DEV = '0'

    expect(() => guard.canActivate(mockCtx(1))).toThrow(HttpException)
  })

  it('allows up to limit and blocks after', () => {
    process.env.AI_DAILY_CALL_LIMIT_DEV = '2'

    expect(guard.canActivate(mockCtx(1))).toBe(true)
    expect(guard.canActivate(mockCtx(1))).toBe(true)
    expect(() => guard.canActivate(mockCtx(1))).toThrow(HttpException)
  })

  it('throws 400 when userId is missing', () => {
    expect(() => guard.canActivate(mockCtx())).toThrow()
  })
})
