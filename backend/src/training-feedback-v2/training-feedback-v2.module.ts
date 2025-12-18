import { Module } from '@nestjs/common'
import { PrismaModule } from '../prisma.module'
import { TrainingFeedbackV2Service } from './training-feedback-v2.service'
import { TrainingFeedbackV2Controller } from './training-feedback-v2.controller'
import { TrainingFeedbackV2AiService } from './training-feedback-v2-ai.service'
import { TrainingFeedbackV2AiController } from './training-feedback-v2-ai.controller'
import { AiCacheModule } from '../ai-cache/ai-cache.module'

@Module({
  imports: [PrismaModule, AiCacheModule],
  providers: [TrainingFeedbackV2Service, TrainingFeedbackV2AiService],
  controllers: [TrainingFeedbackV2Controller, TrainingFeedbackV2AiController],
  exports: [TrainingFeedbackV2Service],
})
export class TrainingFeedbackV2Module {}

