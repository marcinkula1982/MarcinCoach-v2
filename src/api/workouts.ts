import type { WorkoutSummary } from '../types'
import type { WorkoutMeta } from '../types/workoutMeta'
import type { WeeklyPlan } from '../types/weekly-plan'
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
