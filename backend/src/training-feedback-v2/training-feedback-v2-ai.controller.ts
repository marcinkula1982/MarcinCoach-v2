import { Body, Controller, Post, Req, UseGuards } from '@nestjs/common'
import { SessionAuthGuard } from '../auth/session-auth.guard'
import { TrainingFeedbackV2AiService } from './training-feedback-v2-ai.service'
import type { Request } from 'express'

@UseGuards(SessionAuthGuard)
@Controller('training-feedback-v2/ai')
export class TrainingFeedbackV2AiController {
  constructor(private readonly service: TrainingFeedbackV2AiService) {}

  @Post('answer')
  async answer(
    @Req() req: Request & { authUser?: { id: number } },
    @Body() body: { feedbackId: number; question: string },
  ) {
    const userId = req.authUser!.id
    return this.service.answerQuestion(body.feedbackId, userId, body.question)
  }
}

