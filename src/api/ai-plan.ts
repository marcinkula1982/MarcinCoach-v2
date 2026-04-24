import type { AiPlanResponse } from '../types/ai-plan'
import client from './client'

const buildAuthHeaders = (): Record<string, string> => {
  const sessionToken = localStorage.getItem('tcx-session-token')
  const username = localStorage.getItem('tcx-username')

  const headers: Record<string, string> = {}
  if (sessionToken) headers['x-session-token'] = sessionToken
  if (username) headers['x-username'] = username
  return headers
}

export async function fetchAiPlan(
  days = 28,
  opts?: { signal?: AbortSignal },
): Promise<AiPlanResponse> {
  const res = await client.get<AiPlanResponse>('/ai/plan', {
    params: { days },
    // /ai/plan may call OpenAI and can take longer than the default 15s client timeout
    timeout: 60_000,
    headers: buildAuthHeaders(),
    ...(opts?.signal ? { signal: opts.signal } : {}),
  })
  return res.data
}
