import { Module } from '@nestjs/common'
import { WorkoutsModule } from './workouts/workouts.module'
import { AppController } from './app.controller'
import { AuthModule } from './auth/auth.module'

@Module({
  imports: [WorkoutsModule, AuthModule],
  controllers: [AppController],
})
export class AppModule {}
