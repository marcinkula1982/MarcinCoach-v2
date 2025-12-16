import { Injectable, InternalServerErrorException } from '@nestjs/common'
import { TrainingSignalsService } from '../training-signals/training-signals.service'
import { UserProfileService } from '../user-profile/user-profile.service'
import type { TrainingContext } from './training-context.types'
import { trainingContextSchema } from './training-context.schema'

@Injectable()
export class TrainingContextService {
  constructor(
    private readonly trainingSignalsService: TrainingSignalsService,
    private readonly userProfileService: UserProfileService,
  ) {}

  async getContextForUser(userId: number, opts?: { days?: number }): Promise<TrainingContext> {
    const days = opts?.days ?? 28

    // Get signals
    const signals = await this.trainingSignalsService.getSignalsForUser(userId, { days })

    // Get profile constraints
    const profile = await this.userProfileService.getConstraintsForUser(userId)

    // Build TrainingContext (deterministic: use signals.period.to, not new Date())
    const context: TrainingContext = {
      generatedAtIso: signals.period.to, // Deterministic from data
      windowDays: days,
      signals,
      profile,
    }

    // Validate via Zod schema
    const parsed = trainingContextSchema.safeParse(context)
    if (!parsed.success) {
      throw new InternalServerErrorException(
        `TrainingContext validation failed: ${JSON.stringify(parsed.error.format())}`,
      )
    }

    return parsed.data
  }
}

