import { Module } from '@nestjs/common'
import { SessionAuthGuard } from './auth/session-auth.guard'
import { PrismaService } from './prisma.service'

@Module({
  providers: [PrismaService, SessionAuthGuard],
  exports: [PrismaService, SessionAuthGuard],
})
export class PrismaModule {}


