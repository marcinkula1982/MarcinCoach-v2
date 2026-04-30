import client from './client'

export type IntegrationStatus = {
  provider: string
  connected: boolean
  lastSyncAt: string | null
  status: string | null
}

export type IntegrationsStatusResponse = {
  integrations: IntegrationStatus[]
}

export async function getIntegrationsStatus(): Promise<IntegrationsStatusResponse> {
  const res = await client.get<IntegrationsStatusResponse>('/integrations/status')
  return res.data
}

export async function disconnectIntegration(provider: string): Promise<void> {
  await client.delete(`/integrations/${encodeURIComponent(provider)}`)
}

export async function connectStrava(): Promise<{ url: string; state: string }> {
  const res = await client.post<{ url: string; state: string }>('/integrations/strava/connect')
  return res.data
}

export async function syncGarmin(days = 30): Promise<{ imported: number; deduped: number }> {
  const fromIso = new Date(Date.now() - days * 86400_000).toISOString().slice(0, 10)
  const res = await client.post<{ imported: number; deduped: number }>('/integrations/garmin/sync', {
    fromIso,
  })
  return res.data
}

export async function syncStrava(): Promise<{ imported: number; deduped: number }> {
  const res = await client.post<{ imported: number; deduped: number }>('/integrations/strava/sync')
  return res.data
}
