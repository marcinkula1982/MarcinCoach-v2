import { Controller, Get } from '@nestjs/common'

@Controller()
export class AppController {
  @Get()
  getRoot() {
    return { status: 'ok', service: 'MarcinCoach API' }
  }

  @Get('health')
  health() {
    return { status: 'ok' }
  }
}


