export type PlanComplianceStatus = 'OK' | 'MINOR_DEVIATION' | 'MAJOR_DEVIATION'

export interface PlanCompliance {
  status: PlanComplianceStatus
  durationRatio?: number
  distanceRatio?: number
  overshootDuration?: boolean
  undershootDuration?: boolean
  overshootDistance?: boolean
  undershootDistance?: boolean
  unplannedSession?: boolean
  skippedPlannedSession?: boolean
  plannedMissing?: boolean
}

