import type { Character, HrStability, Economy, LoadImpact, CoachSignals, Metrics } from './training-feedback-v2.types'
import type { IntensityBuckets } from '../types/metrics.types'
import type { WorkoutSummary } from '../types/workout.types'
import type { Trackpoint } from '../types/tcx.types'

export function determineCharacter(intensity: IntensityBuckets | null, durationSec: number): Character {
  if (!intensity) {
    return durationSec < 600 ? 'regeneracja' : 'easy'
  }

  const totalSec = intensity.z1Sec + intensity.z2Sec + intensity.z3Sec + intensity.z4Sec + intensity.z5Sec
  if (totalSec === 0) {
    return durationSec < 600 ? 'regeneracja' : 'easy'
  }

  const z1Pct = (intensity.z1Sec / totalSec) * 100
  const z2Pct = (intensity.z2Sec / totalSec) * 100
  const z3Pct = (intensity.z3Sec / totalSec) * 100
  const z4Pct = (intensity.z4Sec / totalSec) * 100
  const z5Pct = (intensity.z5Sec / totalSec) * 100

  // Interwał: znaczący z5 lub wysokie z4+z5
  if (z5Pct > 20 || z4Pct + z5Pct > 40) {
    return 'interwał'
  }

  // Tempo: głównie z3-z4
  if (z3Pct > 30 || z4Pct > 20) {
    return 'tempo'
  }

  // Regeneracja: bardzo krótki
  if (durationSec < 600) {
    return 'regeneracja'
  }

  // Easy: głównie z1-z2
  if (z1Pct > 70 || z1Pct + z2Pct > 80) {
    return 'easy'
  }

  return 'easy' // domyślnie
}

export function analyzeHrStability(trackpoints: Trackpoint[]): HrStability {
  const hrValues: Array<{ time: number; hr: number }> = []

  for (const tp of trackpoints) {
    if (tp.heartRateBpm != null && tp.time) {
      const timeMs = new Date(tp.time).getTime()
      if (Number.isFinite(timeMs)) {
        hrValues.push({ time: timeMs, hr: tp.heartRateBpm })
      }
    }
  }

  if (hrValues.length < 2) {
    return { drift: null, artefacts: false }
  }

  // Sortuj po czasie
  hrValues.sort((a, b) => a.time - b.time)

  // Sprawdź artefakty: skoki >30 bpm między sąsiednimi punktami
  let hasArtefacts = false
  for (let i = 1; i < hrValues.length; i++) {
    const diff = Math.abs(hrValues[i]!.hr - hrValues[i - 1]!.hr)
    if (diff > 30) {
      hasArtefacts = true
      break
    }
  }

  // Drift: regresja liniowa HR vs czas (bpm/min)
  // y = ax + b, gdzie y = HR, x = czas (w minutach)
  const n = hrValues.length
  const startTime = hrValues[0]!.time
  const endTime = hrValues[n - 1]!.time
  const durationMin = (endTime - startTime) / (1000 * 60)

  if (durationMin <= 0 || !Number.isFinite(durationMin)) {
    return { drift: null, artefacts: hasArtefacts }
  }

  // Normalizuj czas do minut od początku
  const x = hrValues.map((v) => (v.time - startTime) / (1000 * 60))
  const y = hrValues.map((v) => v.hr)

  // Oblicz średnie
  const xMean = x.reduce((sum, val) => sum + val, 0) / n
  const yMean = y.reduce((sum, val) => sum + val, 0) / n

  // Oblicz współczynniki regresji liniowej
  let numerator = 0
  let denominator = 0

  for (let i = 0; i < n; i++) {
    const dx = x[i]! - xMean
    const dy = y[i]! - yMean
    numerator += dx * dy
    denominator += dx * dx
  }

  const slope = denominator !== 0 ? numerator / denominator : 0
  const drift = Number.isFinite(slope) ? Number(slope.toFixed(2)) : null

  return { drift, artefacts: hasArtefacts }
}

export function analyzeEconomy(trackpoints: Trackpoint[]): Economy {
  // Filtrowanie segmentów: ignoruj postoje (dt > 30s) i GPS noise (dd <= 0)
  // Podobnie jak w WorkoutsService.computeIntensityBucketsFromTrackpoints()
  const segments: Array<{ pace: number; dt: number }> = []

  const toSec = (iso: string) => Math.floor(new Date(iso).getTime() / 1000)

  for (let i = 1; i < trackpoints.length; i++) {
    const prev = trackpoints[i - 1]
    const cur = trackpoints[i]

    if (!prev?.time || !cur?.time) continue

    const t0 = toSec(prev.time)
    const t1 = toSec(cur.time)
    const dt = t1 - t0

    // Filtr na postoje i dziury
    if (dt <= 0 || dt > 30) continue

    const d0 = prev.distanceMeters
    const d1 = cur.distanceMeters
    if (d0 == null || d1 == null) continue

    const dd = d1 - d0
    // Filtr na GPS noise
    if (dd <= 0) continue

    const pace = dt / (dd / 1000) // sec/km
    if (!Number.isFinite(pace) || pace <= 0) continue

    segments.push({ pace, dt })
  }

  if (segments.length === 0) {
    return { paceEquality: 0, variance: 0 }
  }

  // Oblicz średnie tempo
  const totalDt = segments.reduce((sum, s) => sum + s.dt, 0)
  const meanPace = segments.reduce((sum, s) => sum + s.pace * s.dt, 0) / totalDt

  if (!Number.isFinite(meanPace) || meanPace <= 0) {
    return { paceEquality: 0, variance: 0 }
  }

  // Oblicz wariancję (ważoną)
  let variance = 0
  for (const seg of segments) {
    const diff = seg.pace - meanPace
    variance += diff * diff * seg.dt
  }
  variance = variance / totalDt

  // Oblicz CV (coefficient of variation) = stdDev / mean
  const stdDev = Math.sqrt(variance)
  const cv = stdDev / meanPace

  // Pace equality: 1 - CV (im wyższe, tym bardziej równomierne tempo)
  const paceEquality = Math.max(0, Math.min(1, 1 - cv))

  return {
    paceEquality: Number(paceEquality.toFixed(3)),
    variance: Number(variance.toFixed(2)),
  }
}

export function calculateLoadImpact(summary: WorkoutSummary): LoadImpact {
  // Weekly load contribution: użyj summary.intensity (liczba) - kompatybilne z TrainingSignalsService
  const weeklyLoadContribution =
    typeof summary.intensity === 'number' && Number.isFinite(summary.intensity) ? summary.intensity : 0

  // Intensity score: ważona suma intensity buckets (opcjonalnie, dla informacji)
  let intensityScore = 0
  if (summary.intensity && typeof summary.intensity === 'object') {
    const buckets = summary.intensity
    // Ważone: z1=1, z2=2, z3=3, z4=4, z5=5
    intensityScore =
      (buckets.z1Sec || 0) * 1 +
      (buckets.z2Sec || 0) * 2 +
      (buckets.z3Sec || 0) * 3 +
      (buckets.z4Sec || 0) * 4 +
      (buckets.z5Sec || 0) * 5
  }

  return {
    weeklyLoadContribution: Number(weeklyLoadContribution.toFixed(0)),
    intensityScore: Number(intensityScore.toFixed(0)),
  }
}

export function calculateCoachSignals(feedback: {
  character: Character
  hrStability: HrStability
  economy: Economy
  loadImpact: LoadImpact
}): CoachSignals {
  const hrStable = (feedback.hrStability.drift == null || Math.abs(feedback.hrStability.drift) <= 2) && !feedback.hrStability.artefacts
  const economyGood = feedback.economy.paceEquality > 0.8
  const loadHeavy = feedback.loadImpact.weeklyLoadContribution > 50

  return {
    character: feedback.character,
    hrStable,
    economyGood,
    loadHeavy,
  }
}

export function calculateMetrics(feedback: {
  hrStability: HrStability
  economy: Economy
  loadImpact: LoadImpact
}): Metrics {
  return {
    hrDrift: feedback.hrStability.drift,
    paceEquality: feedback.economy.paceEquality,
    weeklyLoadContribution: feedback.loadImpact.weeklyLoadContribution,
  }
}

