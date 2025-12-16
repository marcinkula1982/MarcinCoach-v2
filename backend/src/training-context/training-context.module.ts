import { Module } from '@nestjs/common'
import { TrainingContextService } from './training-context.service'
import { TrainingContextController } from './training-context.controller'
import { TrainingSignalsModule } from '../training-signals/training-signals.module'
import { UserProfileModule } from '../user-profile/user-profile.module'
import { AuthModule } from '../auth/auth.module'
import { PrismaService } from '../prisma.service'

@Module({
  imports: [TrainingSignalsModule, UserProfileModule, AuthModule], // AuthModule for SessionAuthGuard
  providers: [TrainingContextService, PrismaService],
  controllers: [TrainingContextController],
  exports: [TrainingContextService], // For potential future use
})
export class TrainingContextModule {}

