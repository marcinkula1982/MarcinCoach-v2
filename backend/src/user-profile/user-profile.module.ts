import { Module } from '@nestjs/common'
import { UserProfileService } from './user-profile.service'
import { UserProfileController } from './user-profile.controller'
import { PrismaService } from '../prisma.service'
import { AuthModule } from '../auth/auth.module'

@Module({
  imports: [AuthModule], // For SessionAuthGuard
  providers: [UserProfileService, PrismaService],
  controllers: [UserProfileController],
  exports: [UserProfileService], // Export for TrainingContextModule
})
export class UserProfileModule {}

