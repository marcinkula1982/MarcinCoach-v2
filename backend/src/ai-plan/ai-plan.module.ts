import { Module } from '@nestjs/common'
import { PrismaModule } from '../prisma.module'
import { AiRateLimitModule } from '../ai-rate-limit/ai-rate-limit.module'
import { TrainingAdjustmentsModule } from '../training-adjustments/training-adjustments.module'
import { TrainingContextModule } from '../training-context/training-context.module'
import { WeeklyPlanModule } from '../weekly-plan/weekly-plan.module'
import { TrainingFeedbackV2Module } from '../training-feedback-v2/training-feedback-v2.module'
import { PlanSnapshotModule } from '../plan-snapshot/plan-snapshot.module'
import { AiPlanController } from './ai-plan.controller'
import { AiPlanService } from './ai-plan.service'

@Module({
  imports: [
    PrismaModule,
    AiRateLimitModule,
    TrainingContextModule,
    TrainingAdjustmentsModule,
    WeeklyPlanModule,
    TrainingFeedbackV2Module,
    PlanSnapshotModule,
  ],
  providers: [AiPlanService],
  controllers: [AiPlanController],
  exports: [AiPlanService],
})
export class AiPlanModule {}


