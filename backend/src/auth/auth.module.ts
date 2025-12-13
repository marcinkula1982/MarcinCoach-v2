import { Module } from '@nestjs/common'
import { AuthService } from './auth.service'
import { AuthController } from './auth.controller'
import { PrismaService } from '../prisma.service'
import { SessionAuthGuard } from './session-auth.guard'

@Module({
  controllers: [AuthController],
  providers: [AuthService, PrismaService, SessionAuthGuard],
  exports: [AuthService, SessionAuthGuard],
})
export class AuthModule {}

