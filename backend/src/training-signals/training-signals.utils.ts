import type { TrainingSignalsIntensity } from './training-signals.types'

export const emptyIntensity = (): TrainingSignalsIntensity => ({
  z1Sec: 0,
  z2Sec: 0,
  z3Sec: 0,
  z4Sec: 0,
  z5Sec: 0,
  totalSec: 0,
})

export const accumulateIntensity = (
  a: TrainingSignalsIntensity,
  b: Partial<TrainingSignalsIntensity> | null | undefined,
): TrainingSignalsIntensity => {
  if (!b) return { ...a }
  const safe = {
    z1Sec: b.z1Sec ?? 0,
    z2Sec: b.z2Sec ?? 0,
    z3Sec: b.z3Sec ?? 0,
    z4Sec: b.z4Sec ?? 0,
    z5Sec: b.z5Sec ?? 0,
    totalSec: b.totalSec ?? 0,
  }
  return {
    z1Sec: a.z1Sec + safe.z1Sec,
    z2Sec: a.z2Sec + safe.z2Sec,
    z3Sec: a.z3Sec + safe.z3Sec,
    z4Sec: a.z4Sec + safe.z4Sec,
    z5Sec: a.z5Sec + safe.z5Sec,
    totalSec: a.totalSec + safe.totalSec,
  }
}

