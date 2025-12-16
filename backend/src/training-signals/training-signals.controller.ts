import { BadRequestException, Controller, Get, Query, Req, UseGuards } from '@nestjs/common'
import type { Request } from 'express'
import { SessionAuthGuard } from '../auth/session-auth.guard'
import { TrainingSignalsService } from './training-signals.service'

type AuthedRequest = Request & { authUser?: { userId?: number; id?: number } }

@Controller()
@UseGuards(SessionAuthGuard)
export class TrainingSignalsController {
  constructor(private readonly signals: TrainingSignalsService) {}

  @Get('training-signals')
  async getTrainingSignals(
    @Req() req: AuthedRequest,
    @Query('days') days?: string,
  ) {
    const userId = req.authUser?.userId ?? (req.authUser as any)?.id
    if (!userId) {
      throw new BadRequestException('Missing userId in session')
    }

    const parsedDays = days !== undefined ? Number(days) : undefined
    if (parsedDays !== undefined && (!Number.isFinite(parsedDays) || parsedDays <= 0)) {
      throw new BadRequestException('Invalid days')
    }

    const opts = parsedDays !== undefined ? { days: parsedDays } : undefined
    return this.signals.getSignalsForUser(userId, opts)
  }
}

