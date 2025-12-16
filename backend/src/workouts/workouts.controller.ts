import {
  Body,
  BadRequestException,
  Controller,
  Delete,
  Get,
  HttpCode,
  Param,
  ParseIntPipe,
  Patch,
  Post,
  Query,
  Req,
  UploadedFile,
  UseGuards,
  UseInterceptors,
  UsePipes,
  ValidationPipe,
} from '@nestjs/common'
import { FileInterceptor } from '@nestjs/platform-express'
import type { Express } from 'express'
import type { Request } from 'express'

import { WorkoutsService } from './workouts.service'
import { SaveWorkoutDto } from './dto/save-workout.dto'
import { UpdateWorkoutMetaDto } from './dto/update-workout-meta.dto'
import { ImportWorkoutDto } from './dto/import-workout.dto'
import { SessionAuthGuard } from '../auth/session-auth.guard'

type AuthedRequest = Request & {
  authUser?: {
    userId?: number
    authUserId?: number
    username?: string
  }
}

@UseGuards(SessionAuthGuard)
@Controller('workouts')
export class WorkoutsController {
  constructor(private readonly workoutsService: WorkoutsService) {}

  private getUserId(req: AuthedRequest): number {
    const userId = req.authUser?.userId
    if (!userId) throw new BadRequestException('Brak userId w sesji')
    return userId
  }

  private getUsername(req: AuthedRequest): string | undefined {
    return req.authUser?.username
  }

  @Get()
  getAll(@Req() req: AuthedRequest) {
    return this.workoutsService.findAllForUser(this.getUserId(req))
  }

  @Get('analytics')
  getAnalytics(@Req() req: AuthedRequest) {
    // TODO[M2-BLOCKER]: endpoint legacy – wymaga refaktoru na getAnalyticsRowsForUser()
    return this.workoutsService.getAnalyticsForUser(this.getUserId(req))
  }

  @Get('analytics/rows')
  getAnalyticsRows(@Req() req: AuthedRequest) {
    return this.workoutsService.getAnalyticsRowsForUser(this.getUserId(req))
  }

  @Get('analytics/summary')
  getAnalyticsSummary(
    @Req() req: AuthedRequest,
    @Query('from') from?: string,
    @Query('to') to?: string,
  ) {
    // TODO[M2-BLOCKER]: endpoint legacy – wymaga refaktoru na AnalyticsRows + agregaty po stronie serwisu
    return this.workoutsService.getAnalyticsSummaryForUser(this.getUserId(req), from, to)
  }

  @Get('analytics/summary-v2')
  getAnalyticsSummaryV2(
    @Req() req: AuthedRequest,
    @Query('from') from?: string,
    @Query('to') to?: string,
  ) {
    return this.workoutsService.getAnalyticsSummaryForUserV2(this.getUserId(req), from, to)
  }

  @Post()
  @UsePipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true }))
  create(@Req() req: AuthedRequest, @Body() dto: SaveWorkoutDto) {
    return this.workoutsService.create(this.getUserId(req), this.getUsername(req), dto)
  }

  @Get(':id')
  getOne(
    @Param('id', ParseIntPipe) id: number,
    @Req() req: AuthedRequest,
    @Query('includeRaw') includeRaw?: string,
  ) {
    return this.workoutsService.findOneForUser(id, this.getUserId(req), includeRaw === 'true')
  }

  @Post('upload')
  @HttpCode(200)
  @UseInterceptors(FileInterceptor('file'))
  uploadTcxFile(
    @Req() req: AuthedRequest,
    @UploadedFile() file: Express.Multer.File | undefined,
  ) {
    if (!file || !file.buffer) throw new BadRequestException('Brak pliku')
    return this.workoutsService.uploadTcxFile(file, this.getUserId(req), this.getUsername(req))
  }

  @Post('import')
  @HttpCode(200)
  @UsePipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true }))
  importWorkout(@Req() req: AuthedRequest, @Body() dto: ImportWorkoutDto) {
    return this.workoutsService.importWorkout(this.getUserId(req), this.getUsername(req), dto)
  }

  @Patch(':id/meta')
  @UsePipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true }))
  updateMeta(
    @Param('id', ParseIntPipe) id: number,
    @Body() dto: UpdateWorkoutMetaDto,
    @Req() req: AuthedRequest,
  ) {
    return this.workoutsService.updateMeta(id, dto.workoutMeta, this.getUserId(req))
  }

  @Delete(':id')
  remove(@Param('id', ParseIntPipe) id: number, @Req() req: AuthedRequest) {
    return this.workoutsService.deleteByIdForUser(id, this.getUserId(req))
  }
}
