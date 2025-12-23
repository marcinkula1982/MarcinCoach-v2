import type { AiInsights } from '../types/ai-insights'
import { client } from './client'

export type AiInsightsResponse = {
  payload: AiInsights
  cache: 'hit' | 'miss'
}

export async function fetchAiInsights(
  days = 28,
  opts?: { signal?: AbortSignal },
): Promise<AiInsightsResponse> {
  const res = await client.get<AiInsightsResponse>('/ai/insights', {
    params: { days },
    signal: opts?.signal,
    timeout: 30_000,
  })

  return res.data
}

