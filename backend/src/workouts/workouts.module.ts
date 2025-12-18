import { Module } from '@nestjs/common'
import { WorkoutsController } from './workouts.controller'
import { WorkoutsService } from './workouts.service'
import { AuthModule } from '../auth/auth.module'
import { PrismaService } from '../prisma.service'
import { TrainingFeedbackV2Module } from '../training-feedback-v2/training-feedback-v2.module'
import { TrainingFeedbackV2Service } from '../training-feedback-v2/training-feedback-v2.service'

@Module({
  imports: [AuthModule, TrainingFeedbackV2Module],
  controllers: [WorkoutsController],
  providers: [
    WorkoutsService,
    PrismaService,
    {
      provide: 'WORKOUTS_SERVICE_INIT',
      useFactory: (workoutsService: WorkoutsService, feedbackService: TrainingFeedbackV2Service) => {
        workoutsService.setTrainingFeedbackV2Service(feedbackService)
        return true
      },
      inject: [WorkoutsService, TrainingFeedbackV2Service],
    },
  ],
  exports: [WorkoutsService],
})
export class WorkoutsModule {}
