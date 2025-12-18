import { BadRequestException, Controller, Get, Header, Query, Req, UseGuards } from '@nestjs/common'
import type { Request } from 'express'
import { SessionAuthGuard } from '../auth/session-auth.guard'
import { AiDailyRateLimitGuard } from '../ai-rate-limit/ai-daily-rate-limit.guard'
import { TrainingAdjustmentsService } from '../training-adjustments/training-adjustments.service'
import { TrainingContextService } from '../training-context/training-context.service'
import { WeeklyPlanService } from '../weekly-plan/weekly-plan.service'
import { TrainingFeedbackV2Service } from '../training-feedback-v2/training-feedback-v2.service'
import { AiPlanService } from './ai-plan.service'

type AuthedRequest = Request & { authUser?: { userId?: number } }

@UseGuards(SessionAuthGuard, AiDailyRateLimitGuard)
@Controller('ai')
export class AiPlanController {
  constructor(
    private readonly trainingContextService: TrainingContextService,
    private readonly trainingAdjustmentsService: TrainingAdjustmentsService,
    private readonly weeklyPlanService: WeeklyPlanService,
    private readonly trainingFeedbackV2Service: TrainingFeedbackV2Service,
    private readonly aiPlanService: AiPlanService,
  ) {}

  @Get('plan')
  @Header('Cache-Control', 'private, no-cache, must-revalidate')
  async getAiPlan(@Req() req: AuthedRequest, @Query('days') daysQuery?: string) {
    const userId = req.authUser?.userId
    if (!userId) {
      throw new BadRequestException('Missing userId in session')
    }

    const parsedDays = daysQuery !== undefined ? Number(daysQuery) : undefined
    if (parsedDays !== undefined && (!Number.isFinite(parsedDays) || parsedDays <= 0)) {
      throw new BadRequestException('Invalid days')
    }

    const opts = parsedDays !== undefined ? { days: parsedDays } : undefined
    const context = await this.trainingContextService.getContextForUser(userId, opts)

    // Pobierz feedbackSignals PRZED generowaniem adjustments (BEFORE AI)
    const feedbackSignals = await this.trainingFeedbackV2Service.getLatestFeedbackSignalsForUser(userId)
    const adjustments = this.trainingAdjustmentsService.generate(context, feedbackSignals)
    const planBase = this.weeklyPlanService.generatePlan(context, adjustments)
    const plan = {
      ...planBase,
      appliedAdjustmentsCodes: adjustments.adjustments.map((a) => a.code),
    }

    const windowDays = context.windowDays

    // Check cache
    const cached = this.aiPlanService.getCachedResponse(userId, windowDays)
    if (cached) {
      req.res?.setHeader('x-ai-cache', 'hit')
      return cached
    }

    const result = await this.aiPlanService.buildResponse(userId, context, adjustments, plan)
    
    // Store in cache
    this.aiPlanService.setCachedResponse(userId, windowDays, result)
    
    // Set cache header
    req.res?.setHeader('x-ai-cache', 'miss')
    
    return result
  }
}


