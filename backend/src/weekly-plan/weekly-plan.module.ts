import { Module } from '@nestjs/common'
import { TrainingAdjustmentsModule } from '../training-adjustments/training-adjustments.module'
import { TrainingContextModule } from '../training-context/training-context.module'
import { AuthModule } from '../auth/auth.module'
import { PrismaService } from '../prisma.service'
import { WeeklyPlanService } from './weekly-plan.service'
import { WeeklyPlanController } from './weekly-plan.controller'

@Module({
  imports: [TrainingContextModule, TrainingAdjustmentsModule, AuthModule],
  providers: [WeeklyPlanService, PrismaService],
  controllers: [WeeklyPlanController],
  exports: [WeeklyPlanService],
})
export class WeeklyPlanModule {}

