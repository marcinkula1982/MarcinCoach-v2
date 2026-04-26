// src/api/garmin.ts
import client from './client'
import type { PlannedSession } from '../types/weekly-plan'

export interface GarminStatusResponse {
  connected: boolean
  health?: string
  connectorMode?: string
  displayName?: string
  fullName?: string
}

export interface GarminConnectResponse {
  accountRef?: string
  status?: string
  connectorMode?: string
  displayName?: string
  fullName?: string
}

export interface GarminSyncResponse {
  syncRunId: string | number
  fetched: number
  imported: number
  deduped: number
  failed: number
}

export interface GarminWorkoutSendResponse {
  connectorMode?: string
  status?: string
  workoutId?: string | number
  scheduledDate?: string | null
}

export async function garminStatus(): Promise<GarminStatusResponse> {
  const res = await client.get<GarminStatusResponse>('/integrations/garmin/status')
  return res.data
}

export async function garminConnect(
  garminEmail: string,
  garminPassword: string,
): Promise<GarminConnectResponse> {
  const res = await client.post<GarminConnectResponse>('/integrations/garmin/connect', {
    garminEmail,
    garminPassword,
  })
  return res.data
}

export async function garminSendWorkout(
  date: string,
  session: PlannedSession,
  name?: string,
): Promise<GarminWorkoutSendResponse> {
  const res = await client.post<GarminWorkoutSendResponse>('/integrations/garmin/workouts/send', {
    date,
    session,
    name: name ?? null,
  })
  return res.data
}

export async function garminSync(
  fromIso?: string,
  toIso?: string,
  activityType?: string | null,
): Promise<GarminSyncResponse> {
  const res = await client.post<GarminSyncResponse>('/integrations/garmin/sync', {
    fromIso: fromIso ?? null,
    toIso: toIso ?? null,
    activityType: activityType ?? null,
  })
  return res.data
}
