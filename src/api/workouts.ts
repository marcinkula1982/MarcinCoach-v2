import type { WorkoutSummary } from '../types'
import type { WorkoutMeta } from '../types/workoutMeta'
import type {
  CrossTrainingPromptPreference,
  PlannedCrossTrainingActivity,
  WeeklyPlan,
} from '../types/weekly-plan'
import client, { buildAuthHeaders } from './client'

export type WorkoutListItem = {
  id: number
  userId: number
  action: string
  kind: string
  summary: WorkoutSummary
  raceMeta?: unknown
  workoutMeta?: WorkoutMeta
  createdAt: string
}

export type Workout = {
  id: number
  userId: number
  action: string
  kind: string
  summary: WorkoutSummary
  raceMeta?: unknown
  createdAt: string
  tcxRaw?: string | null
}

export type WorkoutFeedback = {
  feedbackId: number
  workoutId: number
  generatedAtIso: string
  summary: {
    character?: string
    distanceKm?: number | null
    movingTimeSec?: number | null
    avgPaceSecPerKm?: number | null
    planCompliance?: string
    durationStatus?: string | null
    hrStatus?: string | null
  }
  praise: string[]
  deviations: string[]
  conclusions: string[]
  planImpact: {
    label: string
    warnings?: Record<string, boolean>
  }
  confidence: string
  metrics: Record<string, unknown>
}

export type ManualCheckInStatus = 'done' | 'modified' | 'skipped'

export type ManualCheckInPayload = {
  plannedSessionDate: string
  plannedSessionId?: string
  status: ManualCheckInStatus
  plannedSession?: Record<string, unknown>
  plannedType?: string
  plannedDurationMin?: number
  plannedIntensity?: string
  actualStartTimeIso?: string
  actualDurationMin?: number
  durationMin?: number
  distanceKm?: number
  sport?: string
  rpe?: number
  mood?: string
  painFlag?: boolean
  painNote?: string
  note?: string
  skipReason?: string
  modificationReason?: string
  planModifications?: Array<Record<string, unknown>>
}

export type ManualCheckIn = {
  id: number
  workoutId: number | null
  plannedSessionDate: string | null
  plannedSessionId: string | null
  status: ManualCheckInStatus
  planCompliance: string
  plannedType: string | null
  plannedDurationMin: number | null
  plannedIntensity: string | null
  plannedSession: Record<string, unknown> | null
  actualDurationMin: number | null
  distanceM: number | null
  distanceKm: number | null
  rpe: number | null
  mood: string | null
  painFlag: boolean
  painNote: string | null
  note: string | null
  skipReason: string | null
  modificationReason: string | null
  planModifications: Array<Record<string, unknown>> | null
  createdAt: string | null
  updatedAt: string | null
}

export type ManualCheckInResponse = {
  created: boolean
  updated: boolean
  checkIn: ManualCheckIn
}

/**
 * Kanoniczna data treningu.
 * UWAGA: wszystkie widoki oparte na WorkoutListItem (lista, this week, itp.)
 * MUSZĄ używać tej funkcji, zamiast sięgać bezpośrednio po createdAt / startTimeIso,
 * żeby uniknąć rozjazdów tygodni / dni między różnymi ekranami.
 */
export const getWorkoutDate = (w: WorkoutListItem) =>
  w.summary?.startTimeIso ?? w.createdAt

export async function getWorkouts(): Promise<WorkoutListItem[]> {
  const response = await client.get<WorkoutListItem[]>('/workouts', {
    headers: buildAuthHeaders(),
  })
  return response.data
}

export async function getWorkout(id: string): Promise<any> {
  const response = await client.get(`/workouts/${id}`, {
    params: { includeRaw: 'true' },
    headers: buildAuthHeaders(),
  })
  return response.data
}

export async function deleteWorkout(id: string): Promise<void> {
  await client.delete(`/workouts/${id}`, {
    headers: buildAuthHeaders(),
  })
}

export const uploadTcxFile = async (file: File): Promise<Workout> => {
  const formData = new FormData()
  formData.append('file', file)

  const response = await client.post<Workout>('/workouts/upload', formData, {
    headers: buildAuthHeaders(),
  })

  return response.data
}

export async function updateWorkoutMeta(id: number, workoutMeta: WorkoutMeta): Promise<void> {
  await client.patch(`/workouts/${id}/meta`, { workoutMeta }, {
    headers: buildAuthHeaders(),
  })
}

export async function getWorkoutFeedback(id: number | string): Promise<WorkoutFeedback> {
  const response = await client.get<WorkoutFeedback>(`/workouts/${id}/feedback`, {
    headers: buildAuthHeaders(),
  })
  return response.data
}

export async function generateWorkoutFeedback(id: number | string): Promise<WorkoutFeedback> {
  const response = await client.post<WorkoutFeedback>(`/workouts/${id}/feedback/generate`, undefined, {
    headers: buildAuthHeaders(),
  })
  return response.data
}

export async function createManualCheckIn(
  payload: ManualCheckInPayload,
): Promise<ManualCheckInResponse> {
  const response = await client.post<ManualCheckInResponse>('/workouts/manual-check-in', payload, {
    headers: buildAuthHeaders(),
  })
  return response.data
}

export async function deleteAllWorkouts(): Promise<{ deleted: number }> {
  const res = await client.delete<{ deleted: number }>('/workouts', {
    headers: buildAuthHeaders(),
  })
  return res.data
}

export async function fetchWeeklyPlan(days = 28): Promise<WeeklyPlan> {
  const res = await client.get<WeeklyPlan>('/weekly-plan', {
    params: { days },
    headers: buildAuthHeaders(),
  })
  return res.data
}

export async function fetchRollingPlan(days = 14): Promise<WeeklyPlan> {
  const res = await client.get<WeeklyPlan>('/rolling-plan', {
    params: { days },
    headers: buildAuthHeaders(),
  })
  return res.data
}

export async function generateRollingPlan(
  days = 14,
  plannedActivities: PlannedCrossTrainingActivity[] = [],
  crossTrainingPromptPreference?: CrossTrainingPromptPreference,
): Promise<WeeklyPlan> {
  const res = await client.post<WeeklyPlan>(
    '/rolling-plan',
    {
      days,
      plannedActivities,
      ...(crossTrainingPromptPreference ? { crossTrainingPromptPreference } : {}),
    },
    {
      headers: buildAuthHeaders(),
    },
  )
  return res.data
}
