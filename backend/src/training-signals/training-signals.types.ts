export type TrainingSignalsIntensity = {
  z1Sec: number
  z2Sec: number
  z3Sec: number
  z4Sec: number
  z5Sec: number
  totalSec: number
}

export type TrainingSignals = {
  period: { from: string; to: string } // ISO
  volume: { distanceKm: number; durationMin: number; sessions: number }
  intensity: TrainingSignalsIntensity
  longRun: { exists: boolean; distanceKm: number; durationMin: number; workoutId: number | null; workoutDt: string | null }
  load: { weeklyLoad: number; rolling4wLoad: number }
  consistency: { sessionsPerWeek: number; streakWeeks: number }
  flags: { injuryRisk: boolean; fatigue: boolean }
}

