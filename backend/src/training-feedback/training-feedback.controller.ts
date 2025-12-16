import { BadRequestException, Controller, Get, Query, Req, UseGuards } from '@nestjs/common'
import type { Request } from 'express'
import { SessionAuthGuard } from '../auth/session-auth.guard'
import { TrainingFeedbackService } from './training-feedback.service'

type AuthedRequest = Request & { authUser?: { userId?: number; id?: number } }

@Controller()
@UseGuards(SessionAuthGuard)
export class TrainingFeedbackController {
  constructor(private readonly trainingFeedbackService: TrainingFeedbackService) {}

  @Get('training-feedback')
  async getTrainingFeedback(@Req() req: AuthedRequest, @Query('days') days?: string) {
    const userId = req.authUser?.userId ?? (req.authUser as any)?.id
    if (!userId) {
      throw new BadRequestException('Missing userId in session')
    }

    const parsedDays = days !== undefined ? Number(days) : undefined
    if (parsedDays !== undefined && (!Number.isFinite(parsedDays) || parsedDays <= 0)) {
      throw new BadRequestException('Invalid days')
    }

    const opts = parsedDays !== undefined ? { days: parsedDays } : undefined
    return this.trainingFeedbackService.getFeedbackForUser(userId, opts)
  }
}


