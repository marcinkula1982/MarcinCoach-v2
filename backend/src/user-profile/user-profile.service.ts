import { Injectable } from '@nestjs/common'
import { PrismaService } from '../prisma.service'
import { UpdateProfileDto } from './dto/profile.dto'
import type { UserProfileConstraints } from '../training-context/training-context.types'

@Injectable()
export class UserProfileService {
  constructor(private prisma: PrismaService) {}

  async getOrCreateProfile(userId: number) {
    let profile = await this.prisma.userProfile.findUnique({
      where: { userId },
    })

    if (!profile) {
      profile = await this.prisma.userProfile.create({
        data: {
          userId,
          preferredRunDays: null,
          preferredSurface: null,
          goals: null,
          constraints: null,
        },
      })
    }

    return profile
  }

  async updateProfile(userId: number, dto: UpdateProfileDto) {
    const data: any = {}

    if (dto.preferredRunDays !== undefined) {
      data.preferredRunDays = dto.preferredRunDays
    }
    if (dto.preferredSurface !== undefined) {
      data.preferredSurface = dto.preferredSurface
    }
    if (dto.goals !== undefined) {
      data.goals = dto.goals
    }
    if (dto.constraints !== undefined) {
      data.constraints = dto.constraints
    }

    const profile = await this.prisma.userProfile.upsert({
      where: { userId },
      update: data,
      create: {
        userId,
        preferredRunDays: dto.preferredRunDays ?? null,
        preferredSurface: dto.preferredSurface ?? null,
        goals: dto.goals ?? null,
        constraints: dto.constraints ?? null,
      },
    })

    return profile
  }

  async getConstraintsForUser(userId: number): Promise<UserProfileConstraints> {
    const profile = await this.prisma.userProfile.findUnique({
      where: { userId },
    })

    // Default constraints (deterministic)
    const defaults: UserProfileConstraints = {
      timezone: 'Europe/Warsaw',
      runningDays: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
      surfaces: { preferTrail: true, avoidAsphalt: true },
      shoes: { avoidZeroDrop: true },
    }

    if (!profile) {
      return defaults
    }

    // Parse preferredRunDays (JSON array of ISO day numbers: 1=Mon, 7=Sun)
    let runningDays: Array<'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun'> = defaults.runningDays
    if (profile.preferredRunDays) {
      try {
        const parsed = JSON.parse(profile.preferredRunDays)
        if (Array.isArray(parsed) && parsed.length > 0) {
          const dayMap: Record<number, 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun'> = {
            1: 'mon',
            2: 'tue',
            3: 'wed',
            4: 'thu',
            5: 'fri',
            6: 'sat',
            7: 'sun',
          }
          runningDays = parsed
            .map((d: number) => dayMap[d])
            .filter((d): d is 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun' => d !== undefined)
          if (runningDays.length === 0) {
            runningDays = defaults.runningDays
          }
          }
      } catch {
        // Fallback to defaults on parse error
      }
    }

    // Parse preferredSurface (string: "TRAIL", "ROAD", etc.)
    let surfaces = defaults.surfaces
    if (profile.preferredSurface) {
      const surfaceUpper = profile.preferredSurface.toUpperCase()
      surfaces = {
        preferTrail: surfaceUpper.includes('TRAIL') || surfaceUpper.includes('TRAIL'),
        avoidAsphalt: surfaceUpper.includes('ROAD') || surfaceUpper.includes('ASPHALT'),
      }
    }

    // Parse constraints JSON (extract shoes, hrZones)
    let shoes = defaults.shoes
    let hrZones: UserProfileConstraints['hrZones'] = undefined
    if (profile.constraints) {
      try {
        const parsed = JSON.parse(profile.constraints)
        if (parsed && typeof parsed === 'object') {
          if (parsed.shoes && typeof parsed.shoes === 'object') {
            shoes = {
              avoidZeroDrop: parsed.shoes.avoidZeroDrop === true,
            }
          }
          if (parsed.hrZones && typeof parsed.hrZones === 'object') {
            const zones = parsed.hrZones
            if (
              Array.isArray(zones.z1) &&
              Array.isArray(zones.z2) &&
              Array.isArray(zones.z3) &&
              Array.isArray(zones.z4) &&
              Array.isArray(zones.z5) &&
              zones.z1.length === 2 &&
              zones.z2.length === 2 &&
              zones.z3.length === 2 &&
              zones.z4.length === 2 &&
              zones.z5.length === 2
            ) {
              hrZones = {
                z1: [zones.z1[0], zones.z1[1]],
                z2: [zones.z2[0], zones.z2[1]],
                z3: [zones.z3[0], zones.z3[1]],
                z4: [zones.z4[0], zones.z4[1]],
                z5: [zones.z5[0], zones.z5[1]],
              }
            }
          }
        }
      } catch {
        // Fallback to defaults on parse error
      }
    }

    const result: UserProfileConstraints = {
      timezone: defaults.timezone, // Always use default for now
      runningDays,
      surfaces,
      shoes,
    }
    if (hrZones !== undefined) {
      result.hrZones = hrZones
    }
    return result
  }
}

