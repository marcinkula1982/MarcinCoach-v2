/**
 * DI token for the RateLimitStore implementation.
 * Kept in its own file to avoid circular imports between the service and the store module.
 */
export const RATE_LIMIT_STORE = Symbol('RATE_LIMIT_STORE')
