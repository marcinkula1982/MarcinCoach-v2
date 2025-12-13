export type Trackpoint = {
  time: string
  distanceMeters?: number
  heartRateBpm?: number
  altitudeMeters?: number
}

export type ParsedTcx = {
  trackpoints: Trackpoint[]
  startTimeIso: string | null
}

export type Metrics = {
  durationSec: number
  distanceM: number
  avgPaceSecPerKm: number | null
  avgHr: number | null
  maxHr: number | null
  count: number
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

export type WorkoutSummary = {
  fileName?: string
  startTimeIso?: string | null
  original?: Metrics | null
  trimmed?: Metrics | null
  totalPoints: number
  selectedPoints: number
}

export type SaveWorkoutPayload = {
  userId: string
  summary: WorkoutSummary
  action: SaveAction
  kind: WorkoutKind
  raceMeta?: RaceMeta
}

