import { Global, Module, Logger } from '@nestjs/common'
import { AiDailyRateLimitGuard } from './ai-daily-rate-limit.guard'
import { AiDailyRateLimitService } from './ai-daily-rate-limit.service'
import { CLOCK, SystemClock } from './clock'
import { RATE_LIMIT_STORE } from './ai-rate-limit.store.token'
import { InMemoryRateLimitStore, RedisRateLimitStore, type RateLimitStore } from './ai-rate-limit.store'

/**
 * Factory: pick the store implementation based on env.
 *
 * When REDIS_URL is set we load `ioredis` lazily (it is an optional dep) and return a
 * RedisRateLimitStore. If the dependency is missing we log a warning and fall back to
 * the in-memory implementation so dev flows keep working even without ioredis installed.
 */
async function rateLimitStoreFactory(): Promise<RateLimitStore> {
  const url = process.env.REDIS_URL
  if (!url) {
    return new InMemoryRateLimitStore()
  }

  const logger = new Logger('AiRateLimit')
  try {
    // Lazy require: keeps ioredis as an OPTIONAL dep.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const Redis = require('ioredis')
    const client = new Redis(url, { lazyConnect: false, maxRetriesPerRequest: 1 })

    client.on('error', (err: Error) => {
      logger.error(`Redis error (rate-limit): ${err.message}`)
    })

    logger.log('Using RedisRateLimitStore (REDIS_URL set)')
    return new RedisRateLimitStore(client)
  } catch (err) {
    logger.warn(
      `REDIS_URL is set but ioredis is unavailable (${(err as Error).message}). ` +
        'Falling back to InMemoryRateLimitStore. Install with: npm i ioredis',
    )
    return new InMemoryRateLimitStore()
  }
}

@Global()
@Module({
  providers: [
    { provide: CLOCK, useClass: SystemClock },
    { provide: RATE_LIMIT_STORE, useFactory: rateLimitStoreFactory },
    AiDailyRateLimitService,
    AiDailyRateLimitGuard,
  ],
  exports: [AiDailyRateLimitService, CLOCK, RATE_LIMIT_STORE],
})
export class AiRateLimitModule {}
