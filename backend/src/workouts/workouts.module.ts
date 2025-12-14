import { Module } from '@nestjs/common'
import { WorkoutsController } from './workouts.controller'
import { WorkoutsService } from './workouts.service'
import { AuthModule } from '../auth/auth.module'
import { PrismaService } from '../prisma.service'

@Module({
  imports: [AuthModule],
  controllers: [WorkoutsController],
  providers: [WorkoutsService, PrismaService],
  exports: [WorkoutsService],
})
export class WorkoutsModule {}
