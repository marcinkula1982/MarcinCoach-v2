import {
  BadRequestException,
  Controller,
  Get,
  Post,
  Param,
  ParseIntPipe,
  Body,
  Req,
  UseGuards,
  UsePipes,
  ValidationPipe,
  NotFoundException,
} from '@nestjs/common'
import { IsNotEmpty, IsString } from 'class-validator'
import type { Request } from 'express'
import { SessionAuthGuard } from '../auth/session-auth.guard'
import { AiDailyRateLimitGuard } from '../ai-rate-limit/ai-daily-rate-limit.guard'
import { TrainingFeedbackV2Service } from './training-feedback-v2.service'
import { TrainingFeedbackV2AiService } from './training-feedback-v2-ai.service'
import { presentFeedback } from './training-feedback-v2-presenter'
import { mapFeedbackToSignals } from './feedback-signals.mapper'

type AuthedRequest = Request & { authUser?: { userId?: number } }

class QuestionDto {
  @IsString()
  @IsNotEmpty()
  question!: string
}

@UseGuards(SessionAuthGuard)
@Controller('training-feedback-v2')
export class TrainingFeedbackV2Controller {
  constructor(
    private readonly trainingFeedbackV2Service: TrainingFeedbackV2Service,
    private readonly trainingFeedbackV2AiService: TrainingFeedbackV2AiService,
  ) {}

  private getUserId(req: AuthedRequest): number {
    const userId = req.authUser?.userId
    if (!userId) {
      throw new BadRequestException('Missing userId in session')
    }
    return userId
  }

  @Get(':workoutId')
  async getFeedback(
    @Param('workoutId', ParseIntPipe) workoutId: number,
    @Req() req: AuthedRequest,
  ) {
    const userId = this.getUserId(req)
    const result = await this.trainingFeedbackV2Service.getFeedbackForWorkout(workoutId, userId)
    if (!result) {
      return null
    }
    return {
      feedbackId: result.id,
      ...presentFeedback(result.feedback, result.createdAt),
    }
  }

  @Post(':workoutId/generate')
  async generate(
    @Param('workoutId', ParseIntPipe) workoutId: number,
    @Req() req: AuthedRequest,
  ) {
    const userId = this.getUserId(req)
    return this.trainingFeedbackV2Service.generateFeedback(workoutId, userId)
  }

  @Get('signals/:workoutId')
  async getSignals(
    @Param('workoutId', ParseIntPipe) workoutId: number,
    @Req() req: AuthedRequest,
  ) {
    const userId = this.getUserId(req)
    const result = await this.trainingFeedbackV2Service.getFeedbackForWorkout(workoutId, userId)
    if (!result) {
      throw new NotFoundException('Feedback not found')
    }
    return mapFeedbackToSignals(result.feedback)
  }

  @Post(':id/question')
  @UseGuards(AiDailyRateLimitGuard)
  @UsePipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true }))
  async answerQuestion(
    @Param('id', ParseIntPipe) feedbackId: number,
    @Body() dto: QuestionDto,
    @Req() req: AuthedRequest,
  ) {
    const userId = this.getUserId(req)
    const result = await this.trainingFeedbackV2AiService.answerQuestion(feedbackId, userId, dto.question)
    
    // Set cache header
    req.res?.setHeader('x-ai-cache', result.cache)
    
    return { answer: result.answer }
  }
}

