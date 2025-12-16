import type { TrainingSignals } from '../training-signals/training-signals.types'

export type UserProfileConstraints = {
  timezone: string // default: 'Europe/Warsaw'
  runningDays: Array<'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun'>
  surfaces: { preferTrail: boolean; avoidAsphalt: boolean }
  shoes: { avoidZeroDrop: boolean }
  hrZones?: {
    z1: [number, number]
    z2: [number, number]
    z3: [number, number]
    z4: [number, number]
    z5: [number, number]
  } // optional
}

export type TrainingContext = {
  generatedAtIso: string
  windowDays: number
  signals: TrainingSignals
  profile: UserProfileConstraints
}

