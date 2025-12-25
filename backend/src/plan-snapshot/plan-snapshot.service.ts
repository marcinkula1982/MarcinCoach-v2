import { Injectable } from '@nestjs/common'
import { PrismaService } from '../prisma.service'
import type { PlanSnapshot } from './plan-snapshot.types'

@Injectable()
export class PlanSnapshotService {
  constructor(private readonly prisma: PrismaService) {}

  async saveForUser(userId: number, snapshot: PlanSnapshot): Promise<void> {
    await this.prisma.planSnapshot.create({
      data: {
        userId,
        snapshotJson: JSON.stringify(snapshot),
        windowStartIso: snapshot.windowStartIso,
        windowEndIso: snapshot.windowEndIso,
      },
    })
  }

  async getForWorkoutDate(
    userId: number,
    workoutDateIso: string,
  ): Promise<PlanSnapshot | null> {
    // Najpierw szukaj snapshot którego window obejmuje workoutDateIso
    const matching = await this.prisma.planSnapshot.findFirst({
      where: {
        userId,
        windowStartIso: { lte: workoutDateIso },
        windowEndIso: { gte: workoutDateIso },
      },
      orderBy: { createdAt: 'desc' },
    })

    if (matching) {
      return JSON.parse(matching.snapshotJson) as PlanSnapshot
    }

    // Fallback: pobierz latest snapshot dla userId
    const latest = await this.prisma.planSnapshot.findFirst({
      where: { userId },
      orderBy: { createdAt: 'desc' },
    })

    if (!latest) {
      return null
    }

    return JSON.parse(latest.snapshotJson) as PlanSnapshot
  }
}

