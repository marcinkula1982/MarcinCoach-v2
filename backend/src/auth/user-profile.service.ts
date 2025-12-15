import { Injectable } from '@nestjs/common'
import { PrismaService } from '../prisma.service'
import { UpdateProfileDto } from './dto/profile.dto'

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
}


