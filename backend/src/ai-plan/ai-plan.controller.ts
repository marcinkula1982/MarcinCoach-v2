import { BadRequestException, Controller, Get, Header, Query, Req, UseGuards } from '@nestjs/common'
import type { Request } from 'express'
import { SessionAuthGuard } from '../auth/session-auth.guard'
import { AiDailyRateLimitGuard } from '../ai-rate-limit/ai-daily-rate-limit.guard'
import { TrainingAdjustmentsService } from '../training-adjustments/training-adjustments.service'
import { TrainingContextService } from '../training-context/training-context.service'
import { WeeklyPlanService } from '../weekly-plan/weekly-plan.service'
import { AiPlanService } from './ai-plan.service'

type AuthedRequest = Request & { authUser?: { userId?: number } }

@UseGuards(SessionAuthGuard, AiDailyRateLimitGuard)
@Controller('ai')
export class AiPlanController {
  constructor(
    private readonly trainingContextService: TrainingContextService,
    private readonly trainingAdjustmentsService: TrainingAdjustmentsService,
    private readonly weeklyPlanService: WeeklyPlanService,
    private readonly aiPlanService: AiPlanService,
  ) {}

  @Get('plan')
  @Header('Cache-Control', 'private, no-cache, must-revalidate')
  async getAiPlan(@Req() req: AuthedRequest, @Query('days') days?: string) {
    const userId = req.authUser?.userId
    if (!userId) {
      throw new BadRequestException('Missing userId in session')
    }

    const parsedDays = days !== undefined ? Number(days) : undefined
    if (parsedDays !== undefined && (!Number.isFinite(parsedDays) || parsedDays <= 0)) {
      throw new BadRequestException('Invalid days')
    }

    const opts = parsedDays !== undefined ? { days: parsedDays } : undefined
    const context = await this.trainingContextService.getContextForUser(userId, opts)

    const adjustments = this.trainingAdjustmentsService.generate(context)
    const planBase = this.weeklyPlanService.generatePlan(context, adjustments)
    const plan = {
      ...planBase,
      appliedAdjustmentsCodes: adjustments.adjustments.map((a) => a.code),
    }

    return await this.aiPlanService.buildResponse(context, adjustments, plan)
  }
}


