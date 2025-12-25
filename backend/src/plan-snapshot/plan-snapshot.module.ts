import { Module } from '@nestjs/common'
import { PlanSnapshotService } from './plan-snapshot.service'
import { PrismaModule } from '../prisma.module'

@Module({
  imports: [PrismaModule],
  providers: [PlanSnapshotService],
  exports: [PlanSnapshotService],
})
export class PlanSnapshotModule {}

