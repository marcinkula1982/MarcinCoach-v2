import { Module } from '@nestjs/common'
import { AuthModule } from '../auth/auth.module'
import { PrismaService } from '../prisma.service'
import { TrainingFeedbackService } from './training-feedback.service'
import { TrainingFeedbackController } from './training-feedback.controller'

@Module({
  imports: [AuthModule],
  providers: [TrainingFeedbackService, PrismaService],
  controllers: [TrainingFeedbackController],
  exports: [TrainingFeedbackService],
})
export class TrainingFeedbackModule {}


