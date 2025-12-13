import { XMLParser } from 'fast-xml-parser'
import type { ParsedTcx, Trackpoint } from '../types'

const toArray = <T>(value: T | T[] | undefined): T[] => {
  if (!value) return []
  return Array.isArray(value) ? value : [value]
}

const parser = new XMLParser({
  ignoreAttributes: false,
  removeNSPrefix: true,
  parseTagValue: true,
  parseAttributeValue: true,
})

export const parseTcx = (xml: string): ParsedTcx => {
  const result = parser.parse(xml)
  const activities = toArray(result?.TrainingCenterDatabase?.Activities?.Activity)

  const trackpoints: Trackpoint[] = []

  activities.forEach((activity: any) => {
    const laps = toArray(activity?.Lap)
    laps.forEach((lap) => {
      const tracks = toArray(lap?.Track)
      tracks.forEach((track) => {
        const points = toArray(track?.Trackpoint)
        points.forEach((tp) => {
          const time = typeof tp?.Time === 'string' ? tp.Time.trim() : null
          if (!time) return
          const distance = tp?.DistanceMeters
          const altitude = tp?.AltitudeMeters
          const hrValue =
            tp?.HeartRateBpm?.Value ?? tp?.HeartRateBpm ?? undefined

          trackpoints.push({
            time,
            distanceMeters:
              typeof distance === 'number' ? Number(distance) : undefined,
            altitudeMeters:
              typeof altitude === 'number' ? Number(altitude) : undefined,
            heartRateBpm:
              typeof hrValue === 'number' ? Math.round(hrValue) : undefined,
          })
        })
      })
    })
  })

  const startTimeIso = trackpoints.find((tp) => tp.time)?.time ?? null

  return { trackpoints, startTimeIso }
}



