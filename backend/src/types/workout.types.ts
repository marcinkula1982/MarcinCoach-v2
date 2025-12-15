import type { IntensityBuckets } from './metrics.types'

export type Metrics = {
  durationSec: number
  distanceM: number
  avgPaceSecPerKm: number | null
  avgHr: number | null
  maxHr: number | null
  count: number
}

export type WorkoutSummary = {
  fileName?: string
  /** ISO start time of the workout, when available */
  startTimeIso?: string | null
  original?: Metrics | null
  trimmed?: Metrics | null
  intensity?: IntensityBuckets | null
  totalPoints: number
  selectedPoints: number
}

export type SaveAction = 'preview-only' | 'save'
export type WorkoutKind = 'training' | 'race'
export type RacePriority = 'A' | 'B' | 'C'
export type RaceDistanceOption = '5 km' | '10 km' | '21.1 km' | '42.2 km' | 'Inny'

export type RaceMeta = {
  name: string
  distance: RaceDistanceOption
  priority: RacePriority
  customDistance?: string
}

export type SaveWorkoutPayload = {
  userId: string
  summary: WorkoutSummary
  action: SaveAction
  kind: WorkoutKind
  raceMeta?: RaceMeta
  tcxRaw: string;
}

export type Workout = {
  id: number
  userId: number
  action: string
  kind: string
  summary: string
  raceMeta?: string | null
  tcxRaw?: string | null
  createdAt: Date
  updatedAt: Date
}


