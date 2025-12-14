export type Trackpoint = {
  time: string
  distanceMeters?: number
  heartRateBpm?: number
  altitudeMeters?: number
}

export type ParsedTcx = {
  trackpoints: Trackpoint[]
  startTimeIso: string | null
}




