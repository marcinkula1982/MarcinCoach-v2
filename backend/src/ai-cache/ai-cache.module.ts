import { Global, Logger, Module } from '@nestjs/common'
import { AiRateLimitModule } from '../ai-rate-limit/ai-rate-limit.module'
import { AiCacheService } from './ai-cache.service'
import { AI_CACHE_STORE } from './ai-cache.store.token'
import { InMemoryAiCacheStore, RedisAiCacheStore, type AiCacheStore } from './ai-cache.store'

/**
 * Factory: picks Redis implementation when REDIS_URL is set; otherwise falls back to in-memory.
 * Mirrors the rate-limit store wiring so both share the same optional ioredis dependency.
 */
async function aiCacheStoreFactory(): Promise<AiCacheStore> {
  const url = process.env.REDIS_URL
  const strictRedis = process.env.NODE_ENV === 'production' || process.env.AI_CACHE_STRICT_REDIS === '1'
  if (!url) {
    return new InMemoryAiCacheStore()
  }

  const logger = new Logger('AiCache')
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const Redis = require('ioredis')
    const client = new Redis(url, { lazyConnect: false, maxRetriesPerRequest: 1 })

    client.on('error', (err: Error) => {
      logger.error(`Redis error (ai-cache): ${err.message}`)
    })

    logger.log('Using RedisAiCacheStore (REDIS_URL set)')
    return new RedisAiCacheStore(client)
  } catch (err) {
    if (strictRedis) {
      throw new Error(
        `AI cache requires Redis in strict mode, but Redis client failed to initialize: ${(err as Error).message}`,
      )
    }
    logger.warn(
      `REDIS_URL is set but ioredis is unavailable (${(err as Error).message}). ` +
        'Falling back to InMemoryAiCacheStore. Install with: npm i ioredis',
    )
    return new InMemoryAiCacheStore()
  }
}

@Global()
@Module({
  imports: [AiRateLimitModule],
  providers: [
    AiCacheService,
    { provide: AI_CACHE_STORE, useFactory: aiCacheStoreFactory },
  ],
  exports: [AiCacheService, AI_CACHE_STORE],
})
export class AiCacheModule {}
