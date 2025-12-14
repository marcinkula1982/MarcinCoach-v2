import type { Trackpoint } from '../types/tcx.types'
import type { Metrics } from '../types/metrics.types'

const emptyMetrics: Metrics = {
  durationSec: 0,
  distanceM: 0,
  avgPaceSecPerKm: null,
  avgHr: null,
  maxHr: null,
  count: 0,
}

export const computeMetrics = (trackpoints: Trackpoint[]): Metrics => {
  if (!trackpoints.length) return emptyMetrics

  const times = trackpoints
    .map((tp) => new Date(tp.time).getTime())
    .filter((t) => Number.isFinite(t))
  const heartRates = trackpoints
    .map((tp) => tp.heartRateBpm)
    .filter((hr): hr is number => hr !== undefined)
  const distances = trackpoints
    .map((tp) => tp.distanceMeters)
    .filter((d): d is number => d !== undefined)

  const durationSec =
    times.length > 1
      ? Math.max(...times) / 1000 - Math.min(...times) / 1000
      : 0

  const distanceM =
    distances.length > 1
      ? Math.max(...distances) - Math.min(...distances)
      : distances[0] ?? 0

  const avgHr =
    heartRates.length > 0
      ? Math.round(
          heartRates.reduce((sum, hr) => sum + hr, 0) / heartRates.length,
        )
      : null

  const maxHr = heartRates.length > 0 ? Math.max(...heartRates) : null

  const avgPaceSecPerKm =
    distanceM > 0 ? durationSec / (distanceM / 1000) : null

  return {
    durationSec,
    distanceM,
    avgPaceSecPerKm,
    avgHr,
    maxHr,
    count: trackpoints.length,
  }
}




