export type PlanCompliance = 'planned' | 'modified' | 'unplanned'

export interface WorkoutMeta {
  planCompliance?: PlanCompliance
  rpe?: number | null
  fatigueFlag?: boolean
}

