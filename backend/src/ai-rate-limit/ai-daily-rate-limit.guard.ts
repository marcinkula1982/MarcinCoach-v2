import {
  BadRequestException,
  CanActivate,
  ExecutionContext,
  HttpException,
  Injectable,
} from '@nestjs/common'
import type { Request } from 'express'
import { AiDailyRateLimitService } from './ai-daily-rate-limit.service'

type AuthedRequest = Request & { authUser?: { userId?: number; id?: number } }

@Injectable()
export class AiDailyRateLimitGuard implements CanActivate {
  constructor(private readonly limiter: AiDailyRateLimitService) {}

  canActivate(ctx: ExecutionContext): boolean {
    const req = ctx.switchToHttp().getRequest<AuthedRequest>()
    const userId = req.authUser?.userId ?? req.authUser?.id
    if (!userId) {
      throw new BadRequestException('Missing userId in session')
    }

    const limit = this.getDailyLimit()

    if (limit === 0) {
      throw new HttpException(
        {
          statusCode: 429,
          message: 'AI disabled by configuration',
        },
        429,
      )
    }

    const result = this.limiter.consume(Number(userId), limit)

    if (!result.allowed) {
      throw new HttpException(
        {
          statusCode: 429,
          message: 'AI daily limit exceeded',
          limit: result.limit,
          used: result.used,
          resetAtIso: result.resetAtIso,
        },
        429,
      )
    }

    return true
  }

  private getDailyLimit(): number {
    const env = (process.env.NODE_ENV || '').toLowerCase()
    const isProd = env === 'production'

    const fallback = isProd ? 25 : 250
    const raw = isProd
      ? process.env.AI_DAILY_CALL_LIMIT_PROD
      : process.env.AI_DAILY_CALL_LIMIT_DEV

    const parsed = Number(raw)
    if (Number.isFinite(parsed) && parsed >= 0) return Math.floor(parsed)
    return fallback
  }
}


