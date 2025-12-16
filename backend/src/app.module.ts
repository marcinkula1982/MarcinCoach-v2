import { Module } from '@nestjs/common'
import { WorkoutsModule } from './workouts/workouts.module'
import { TrainingSignalsModule } from './training-signals/training-signals.module'
import { AppController } from './app.controller'
import { AuthModule } from './auth/auth.module'
import { UserProfileModule } from './user-profile/user-profile.module'
import { TrainingContextModule } from './training-context/training-context.module'
import { WeeklyPlanModule } from './weekly-plan/weekly-plan.module'
import { TrainingFeedbackModule } from './training-feedback/training-feedback.module'

@Module({
  imports: [WorkoutsModule, AuthModule, TrainingSignalsModule, UserProfileModule, TrainingContextModule, WeeklyPlanModule, TrainingFeedbackModule],
  controllers: [AppController],
})
export class AppModule {}
