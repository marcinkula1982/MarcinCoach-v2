import type { WorkoutSummary } from '../types'
import type { WorkoutMeta } from '../types/workoutMeta'
import client from './client'

const buildAuthHeaders = (): Record<string, string> => {
  const sessionToken = localStorage.getItem('tcx-session-token')

  const headers: Record<string, string> = {}
  if (sessionToken) {
    headers['x-session-token'] = sessionToken
  }
  return headers
}

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

