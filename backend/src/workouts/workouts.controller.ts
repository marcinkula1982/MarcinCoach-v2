import {
  Body,
  BadRequestException,
  Controller,
  Delete,
  Get,
  Post,
  Patch,
  Query,
  UploadedFile,
  UsePipes,
  UseInterceptors,
  ValidationPipe,
  Param,
  ParseIntPipe,
  UseGuards,
  Req,
} from '@nestjs/common'
import { FileInterceptor } from '@nestjs/platform-express'
import type { Express } from 'express'
import type { Request } from 'express'
import { WorkoutsService } from './workouts.service'
import { SaveWorkoutDto } from './dto/save-workout.dto'
import { UpdateWorkoutMetaDto } from './dto/update-workout-meta.dto'
import { ImportWorkoutDto } from './dto/import-workout.dto'
import { SessionAuthGuard } from '../auth/session-auth.guard'

@UseGuards(SessionAuthGuard)
@Controller('workouts')
export class WorkoutsController {
  constructor(private readonly workoutsService: WorkoutsService) {}

  private getUserContext(
    req: Request & { authUser?: { userId: number; username: string } },
  ): { userId: number; username: string } {
    const ctx = req.authUser
    if (!ctx || typeof ctx.userId !== 'number') {
      throw new BadRequestException('Brak użytkownika w sesji')
    }
    return { userId: ctx.userId, username: ctx.username }
  }

  @Get()
  getAll(@Req() req: Request & { authUser?: { userId: number; username: string } }) {
    const { userId } = this.getUserContext(req)
    return this.workoutsService.findAllForUser(userId)
  }

  @Get('analytics')
  getAnalytics(@Req() req: Request & { authUser?: { userId: number; username: string } }) {
    const { userId } = this.getUserContext(req)
    return this.workoutsService.getAnalyticsForUser(userId)
  }

  @Get('analytics/summary')
  getAnalyticsSummary(
    @Req() req: Request & { authUser?: { userId: number; username: string } },
    @Query('from') from?: string,
    @Query('to') to?: string,
  ) {
    const { userId } = this.getUserContext(req)
    return this.workoutsService.getAnalyticsSummaryForUser(userId, from, to)
  }

  @Post()
  @UsePipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true }))
  create(
    @Req() req: Request & { authUser?: { userId: number; username: string } },
    @Body() dto: SaveWorkoutDto,
  ) {
    const { userId, username } = this.getUserContext(req)
    return this.workoutsService.create(userId, username, dto)
  }

  @Get(':id')
  async getOne(
    @Param('id', ParseIntPipe) id: number,
    @Req() req: Request & { authUser?: { userId: number; username: string } },
    @Query('includeRaw') includeRaw?: string,
  ) {
    const { userId } = this.getUserContext(req)
    return this.workoutsService.findOneForUser(id, userId, includeRaw === 'true')
  }

  @Post('upload')
  @UseInterceptors(FileInterceptor('file'))
  async uploadTcxFile(
    @Req() req: Request & { authUser?: { userId: number; username: string } },
    @UploadedFile() file: Express.Multer.File | undefined,
  ) {
    if (!file) {
      throw new BadRequestException('Brak pliku')
    }
    const { userId, username } = this.getUserContext(req)
    return this.workoutsService.uploadTcxFile(file, userId, username)
  }

  @Post('import')
  @UsePipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true }))
  async importWorkout(
    @Req() req: Request & { authUser?: { userId: number; username: string } },
    @Body() dto: ImportWorkoutDto,
  ) {
    const { userId, username } = this.getUserContext(req)
    return this.workoutsService.importWorkout(userId, username, dto)
  }

  @Patch(':id/meta')
  @UsePipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true }))
  updateMeta(
    @Param('id', ParseIntPipe) id: number,
    @Body() dto: UpdateWorkoutMetaDto,
    @Req() req: Request & { authUser?: { userId: number; username: string } },
  ) {
    const { userId } = this.getUserContext(req)
    return this.workoutsService.updateMeta(id, dto.workoutMeta, userId)
  }

  @Delete(':id')
  async remove(
    @Param('id', ParseIntPipe) id: number,
    @Req() req: Request & { authUser?: { userId: number; username: string } },
  ) {
    const { userId } = this.getUserContext(req)
    await this.workoutsService.deleteByIdForUser(id, userId)
    return { success: true }
  }
}


