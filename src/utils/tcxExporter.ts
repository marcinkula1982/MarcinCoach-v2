import { computeMetrics } from './metrics'
import type { Trackpoint } from '../types'

const formatNumber = (value: number | null | undefined, digits = 1) => {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return null
  }
  return Number(value).toFixed(digits)
}

export const exportTcx = (trackpoints: Trackpoint[]): string => {
  const metrics = computeMetrics(trackpoints)
  const startTime = trackpoints[0]?.time ?? new Date().toISOString()
  const activityId = startTime
  const totalTime = formatNumber(metrics.durationSec, 1) ?? '0'
  const distance = formatNumber(metrics.distanceM, 2) ?? '0'

  const trackXml = trackpoints
    .map((tp) => {
      const distanceTag =
        tp.distanceMeters !== undefined
          ? `<DistanceMeters>${formatNumber(tp.distanceMeters, 2)}</DistanceMeters>`
          : ''
      const altitudeTag =
        tp.altitudeMeters !== undefined
          ? `<AltitudeMeters>${formatNumber(tp.altitudeMeters, 1)}</AltitudeMeters>`
          : ''
      const hrTag =
        tp.heartRateBpm !== undefined
          ? `<HeartRateBpm><Value>${Math.round(tp.heartRateBpm)}</Value></HeartRateBpm>`
          : ''

      return `
        <Trackpoint>
          <Time>${tp.time}</Time>
          ${distanceTag}
          ${hrTag}
          ${altitudeTag}
        </Trackpoint>
      `
    })
    .join('\n')

  return `<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <Activities>
    <Activity Sport="Other">
      <Id>${activityId}</Id>
      <Lap StartTime="${startTime}">
        <TotalTimeSeconds>${totalTime}</TotalTimeSeconds>
        <DistanceMeters>${distance}</DistanceMeters>
        <Track>
          ${trackXml}
        </Track>
      </Lap>
    </Activity>
  </Activities>
</TrainingCenterDatabase>`
}



