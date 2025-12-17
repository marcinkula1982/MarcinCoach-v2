import { Module } from '@nestjs/common'
import { AuthModule } from '../auth/auth.module'
import { PrismaService } from '../prisma.service'
import { TrainingFeedbackModule } from '../training-feedback/training-feedback.module'
import { UserProfileModule } from '../user-profile/user-profile.module'
import { AiInsightsController } from './ai-insights.controller'
import { AiInsightsService } from './ai-insights.service'

@Module({
  imports: [AuthModule, TrainingFeedbackModule, UserProfileModule],
  providers: [AiInsightsService, PrismaService],
  controllers: [AiInsightsController],
  exports: [AiInsightsService],
})
export class AiInsightsModule {}


