import { Global, Module } from '@nestjs/common'
import { AiRateLimitModule } from '../ai-rate-limit/ai-rate-limit.module'
import { AiCacheService } from './ai-cache.service'

@Global()
@Module({
  imports: [AiRateLimitModule],
  providers: [AiCacheService],
  exports: [AiCacheService],
})
export class AiCacheModule {}

