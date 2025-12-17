import { BadRequestException, Controller, Get, Query, Req, UseGuards } from '@nestjs/common'
import type { Request } from 'express'
import { SessionAuthGuard } from '../auth/session-auth.guard'
import { AiInsightsService } from './ai-insights.service'

type AuthedRequest = Request & { authUser?: { userId?: number; id?: number; username?: string } }

@Controller()
@UseGuards(SessionAuthGuard)
export class AiInsightsController {
  constructor(private readonly aiInsightsService: AiInsightsService) {}

  @Get('ai/insights')
  async getAiInsights(@Req() req: AuthedRequest, @Query('days') days?: string) {
    const userId = req.authUser?.userId ?? (req.authUser as any)?.id
    if (!userId) {
      throw new BadRequestException('Missing userId in session')
    }
    const username = req.authUser?.username
    if (!username) {
      throw new BadRequestException('Missing username in session')
    }

    const parsedDays = days !== undefined ? Number(days) : undefined
    if (parsedDays !== undefined && (!Number.isFinite(parsedDays) || parsedDays <= 0)) {
      throw new BadRequestException('Invalid days')
    }

    const opts = parsedDays !== undefined ? { days: parsedDays } : undefined
    return this.aiInsightsService.getInsightsForUser(userId, username, opts)
  }
}


