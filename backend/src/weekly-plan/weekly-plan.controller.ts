import { BadRequestException, Controller, Get, Query, Req, UseGuards } from '@nestjs/common'
import type { Request } from 'express'
import { SessionAuthGuard } from '../auth/session-auth.guard'
import { TrainingContextService } from '../training-context/training-context.service'
import { WeeklyPlanService } from './weekly-plan.service'

type AuthedRequest = Request & { authUser?: { userId?: number; id?: number } }

@Controller()
@UseGuards(SessionAuthGuard)
export class WeeklyPlanController {
  constructor(
    private readonly trainingContextService: TrainingContextService,
    private readonly weeklyPlanService: WeeklyPlanService,
  ) {}

  @Get('weekly-plan')
  async getWeeklyPlan(@Req() req: AuthedRequest, @Query('days') days?: string) {
    const userId = req.authUser?.userId ?? (req.authUser as any)?.id
    if (!userId) {
      throw new BadRequestException('Missing userId in session')
    }

    const parsedDays = days !== undefined ? Number(days) : undefined
    if (parsedDays !== undefined && (!Number.isFinite(parsedDays) || parsedDays <= 0)) {
      throw new BadRequestException('Invalid days')
    }

    const opts = parsedDays !== undefined ? { days: parsedDays } : undefined
    const ctx = await this.trainingContextService.getContextForUser(userId, opts)
    const plan = this.weeklyPlanService.generatePlan(ctx)

    return plan
  }
}

