export type PlanSnapshotDay = {
  dateKey: string // Format: 'YYYY-MM-DD' (nie pełny ISO)
  type: 'easy' | 'long' | 'tempo' | 'interval' | 'rest'
  plannedDurationMin?: number
  plannedDistanceKm?: number
  plannedIntensity?: 'easy' | 'moderate' | 'hard'
}

export type PlanSnapshot = {
  windowStartIso: string
  windowEndIso: string
  days: PlanSnapshotDay[]
}

