import type { OnboardingSummary } from '../types/onboarding-summary'
import { client } from './client'

export async function fetchOnboardingSummary(
  days = 90,
  opts?: { signal?: AbortSignal },
): Promise<OnboardingSummary> {
  const res = await client.get<OnboardingSummary>('/me/onboarding-summary', {
    params: { days },
    signal: opts?.signal,
  })

  return res.data
}
