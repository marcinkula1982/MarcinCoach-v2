import { Module } from '@nestjs/common'
import { TrainingSignalsService } from './training-signals.service'
import { TrainingSignalsController } from './training-signals.controller'
import { PrismaService } from '../prisma.service'
import { SessionAuthGuard } from '../auth/session-auth.guard'

@Module({
  controllers: [TrainingSignalsController],
  providers: [TrainingSignalsService, PrismaService, SessionAuthGuard],
  exports: [TrainingSignalsService],
})
export class TrainingSignalsModule {}

