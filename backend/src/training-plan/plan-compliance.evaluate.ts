import type { PlanCompliance, PlanComplianceStatus } from './plan-compliance.types'
import type { PlanSnapshotDay } from '../plan-snapshot/plan-snapshot.types'

type ActualWorkout = {
  durationMin: number
  distanceKm?: number
}

export function evaluatePlanCompliance(
  planned: PlanSnapshotDay | null,
  actual: ActualWorkout | null,
): PlanCompliance {
  // plannedMissing: Jeśli planned jest null/undefined
  if (!planned) {
    return {
      plannedMissing: true,
      status: 'MAJOR_DEVIATION',
    }
  }

  // rest + actual: Jeśli planned.type === 'rest' i actual !== null
  if (planned.type === 'rest' && actual !== null) {
    return {
      unplannedSession: true,
      status: 'MAJOR_DEVIATION',
    }
  }

  // non-rest + no actual: Jeśli planned.type !== 'rest' i actual === null
  if (planned.type !== 'rest' && actual === null) {
    return {
      skippedPlannedSession: true,
      status: 'MAJOR_DEVIATION',
    }
  }

  // Jeśli actual jest null (a planned nie jest rest), już zwróciliśmy skippedPlannedSession
  if (actual === null) {
    return {
      skippedPlannedSession: true,
      status: 'MAJOR_DEVIATION',
    }
  }

  const flags: {
    overshootDuration?: boolean
    undershootDuration?: boolean
    overshootDistance?: boolean
    undershootDistance?: boolean
  } = {}
  let status: PlanComplianceStatus = 'OK'
  let durationRatio: number | undefined
  let distanceRatio: number | undefined

  // Duration thresholds (jeśli planned.plannedDurationMin istnieje)
  if (planned.plannedDurationMin && planned.plannedDurationMin > 0) {
    durationRatio = actual.durationMin / planned.plannedDurationMin

    if (durationRatio < 0.7) {
      flags.undershootDuration = true
      status = 'MAJOR_DEVIATION'
    } else if (durationRatio >= 0.7 && durationRatio < 0.85) {
      flags.undershootDuration = true
      if (status === 'OK') status = 'MINOR_DEVIATION'
    } else if (durationRatio >= 1.15 && durationRatio <= 1.3) {
      flags.overshootDuration = true
      if (status === 'OK') status = 'MINOR_DEVIATION'
    } else if (durationRatio > 1.3) {
      flags.overshootDuration = true
      status = 'MAJOR_DEVIATION'
    }
  }

  // Distance thresholds (jeśli planned.plannedDistanceKm i actual.distanceKm istnieją)
  if (
    planned.plannedDistanceKm &&
    planned.plannedDistanceKm > 0 &&
    actual.distanceKm !== undefined &&
    actual.distanceKm !== null
  ) {
    distanceRatio = actual.distanceKm / planned.plannedDistanceKm

    if (distanceRatio < 0.7) {
      flags.undershootDistance = true
      status = 'MAJOR_DEVIATION'
    } else if (distanceRatio >= 0.7 && distanceRatio < 0.85) {
      flags.undershootDistance = true
      if (status === 'OK') status = 'MINOR_DEVIATION'
    } else if (distanceRatio >= 1.15 && distanceRatio <= 1.3) {
      flags.overshootDistance = true
      if (status === 'OK') status = 'MINOR_DEVIATION'
    } else if (distanceRatio > 1.3) {
      flags.overshootDistance = true
      status = 'MAJOR_DEVIATION'
    }
  }

  return {
    status,
    ...(durationRatio !== undefined ? { durationRatio } : {}),
    ...(distanceRatio !== undefined ? { distanceRatio } : {}),
    ...flags,
  }
}

