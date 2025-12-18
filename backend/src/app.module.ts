import { Module } from '@nestjs/common'
import { WorkoutsModule } from './workouts/workouts.module'
import { TrainingSignalsModule } from './training-signals/training-signals.module'
import { AppController } from './app.controller'
import { AuthModule } from './auth/auth.module'
import { UserProfileModule } from './user-profile/user-profile.module'
import { TrainingContextModule } from './training-context/training-context.module'
import { WeeklyPlanModule } from './weekly-plan/weekly-plan.module'
import { TrainingFeedbackModule } from './training-feedback/training-feedback.module'
import { AiInsightsModule } from './ai-insights/ai-insights.module'
import { TrainingAdjustmentsModule } from './training-adjustments/training-adjustments.module'
import { AiPlanModule } from './ai-plan/ai-plan.module'
import { AiRateLimitModule } from './ai-rate-limit/ai-rate-limit.module'
import { AiCacheModule } from './ai-cache/ai-cache.module'
import { TrainingFeedbackV2Module } from './training-feedback-v2/training-feedback-v2.module'

@Module({
  imports: [
    WorkoutsModule,
    AuthModule,
    TrainingSignalsModule,
    UserProfileModule,
    TrainingContextModule,
    WeeklyPlanModule,
    TrainingFeedbackModule,
    AiInsightsModule,
    TrainingAdjustmentsModule,
    AiPlanModule,
    AiRateLimitModule,
    AiCacheModule,
    TrainingFeedbackV2Module,
  ],
  controllers: [AppController],
})
export class AppModule {}
