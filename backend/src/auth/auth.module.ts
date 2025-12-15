import { Module } from '@nestjs/common'
import { AuthService } from './auth.service'
import { AuthController } from './auth.controller'
import { PrismaService } from '../prisma.service'
import { SessionAuthGuard } from './session-auth.guard'
import { UserProfileService } from './user-profile.service'
import { UserProfileController } from './user-profile.controller'

@Module({
  controllers: [AuthController, UserProfileController],
  providers: [AuthService, PrismaService, SessionAuthGuard, UserProfileService],
  exports: [AuthService, SessionAuthGuard],
})
export class AuthModule {}

