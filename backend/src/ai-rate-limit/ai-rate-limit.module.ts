import { Global, Module } from '@nestjs/common'
import { AiDailyRateLimitGuard } from './ai-daily-rate-limit.guard'
import { AiDailyRateLimitService } from './ai-daily-rate-limit.service'
import { CLOCK, SystemClock } from './clock'

@Global()
@Module({
  providers: [
    { provide: CLOCK, useClass: SystemClock },
    AiDailyRateLimitService,
    AiDailyRateLimitGuard,
  ],
  exports: [AiDailyRateLimitService, CLOCK],
})
export class AiRateLimitModule {}


