import { BadRequestException, Controller, Get, Header, Query, Req, UseGuards } from '@nestjs/common'
import type { Request } from 'express'
import { SessionAuthGuard } from '../auth/session-auth.guard'
import { TrainingContextService } from '../training-context/training-context.service'
import { TrainingAdjustmentsService } from './training-adjustments.service'

type AuthedRequest = Request & { authUser?: { userId?: number } }

@UseGuards(SessionAuthGuard)
@Controller('training-adjustments')
export class TrainingAdjustmentsController {
  constructor(
    private readonly trainingContextService: TrainingContextService,
    private readonly trainingAdjustmentsService: TrainingAdjustmentsService,
  ) {}

  @Get()
  @Header('Cache-Control', 'private, no-cache, must-revalidate')
  async getTrainingAdjustments(@Req() req: AuthedRequest, @Query('days') days?: string) {
    const userId = req.authUser?.userId
    if (!userId) {
      throw new BadRequestException('Missing userId in session')
    }

    const parsedDays = days !== undefined ? Number(days) : undefined
    if (parsedDays !== undefined && (!Number.isFinite(parsedDays) || parsedDays <= 0)) {
      throw new BadRequestException('Invalid days')
    }

    const opts = parsedDays !== undefined ? { days: parsedDays } : undefined
    const ctx = await this.trainingContextService.getContextForUser(userId, opts)
    return this.trainingAdjustmentsService.generate(ctx)
  }
}


