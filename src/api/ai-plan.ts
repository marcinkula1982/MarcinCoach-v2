import type { AiPlanResponse } from '../types/ai-plan'
import client from './client'

export async function fetchAiPlan(
  days = 28,
  opts?: { signal?: AbortSignal },
): Promise<AiPlanResponse> {
  const res = await client.get<AiPlanResponse>('/ai/plan', {
    params: { days },
    // /ai/plan may call OpenAI and can take longer than the default 15s client timeout
    timeout: 60_000,
    ...(opts?.signal ? { signal: opts.signal } : {}),
  })
  return res.data
}


