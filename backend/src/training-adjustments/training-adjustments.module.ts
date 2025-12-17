import { Module } from '@nestjs/common'
import { PrismaModule } from '../prisma.module'
import { TrainingContextModule } from '../training-context/training-context.module'
import { TrainingAdjustmentsController } from './training-adjustments.controller'
import { TrainingAdjustmentsService } from './training-adjustments.service'

@Module({
  imports: [PrismaModule, TrainingContextModule],
  providers: [TrainingAdjustmentsService],
  exports: [TrainingAdjustmentsService],
  controllers: [TrainingAdjustmentsController],
})
export class TrainingAdjustmentsModule {}


