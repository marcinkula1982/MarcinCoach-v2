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

  private getUsername(req: Request & { authUser?: { username: string } }): string {
    const username = req.authUser?.username
    if (!username) {
      throw new BadRequestException('Brak użytkownika w sesji')
    }
    return username
  }

  @Get()
  getAll(@Req() req: Request & { authUser?: { username: string } }) {
    return this.workoutsService.findAllForUser(this.getUsername(req))
  }

  @Get('analytics')
  getAnalytics(@Req() req: Request & { authUser?: { username: string } }) {
    return this.workoutsService.getAnalyticsForUser(this.getUsername(req))
  }

  @Get('analytics/summary')
  getAnalyticsSummary(
    @Req() req: Request & { authUser?: { username: string } },
    @Query('from') from?: string,
    @Query('to') to?: string,
  ) {
    const userId = req.authUser!.username
    return this.workoutsService.getAnalyticsSummaryForUser(userId, from, to)
  }

  @Post()
  @UsePipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true }))
  create(
    @Req() req: Request & { authUser?: { username: string } },
    @Body() dto: SaveWorkoutDto,
  ) {
    return this.workoutsService.create(this.getUsername(req), dto)
  }

  @Get(':id')
  async getOne(
    @Param('id', ParseIntPipe) id: number,
    @Req() req: Request & { authUser?: { username: string } },
    @Query('includeRaw') includeRaw?: string,
  ) {
    return this.workoutsService.findOneForUser(id, this.getUsername(req), includeRaw === 'true')
  }

  @Post('upload')
  @UseInterceptors(FileInterceptor('file'))
  async uploadTcxFile(
    @Req() req: Request & { authUser?: { username: string } },
    @UploadedFile() file: Express.Multer.File | undefined,
  ) {
    if (!file) {
      throw new BadRequestException('Brak pliku')
    }
    return this.workoutsService.uploadTcxFile(file, this.getUsername(req))
  }

  @Post('import')
  @UsePipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true }))
  async importWorkout(
    @Req() req: Request & { authUser?: { username: string } },
    @Body() dto: ImportWorkoutDto,
  ) {
    return this.workoutsService.importWorkout(this.getUsername(req), dto)
  }

  @Patch(':id/meta')
  @UsePipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true }))
  updateMeta(
    @Param('id', ParseIntPipe) id: number,
    @Body() dto: UpdateWorkoutMetaDto,
    @Req() req: Request & { authUser?: { username: string } },
  ) {
    return this.workoutsService.updateMeta(id, dto.workoutMeta, this.getUsername(req))
  }

  @Delete(':id')
  async remove(
    @Param('id', ParseIntPipe) id: number,
    @Req() req: Request & { authUser?: { username: string } },
  ) {
    await this.workoutsService.deleteByIdForUser(id, this.getUsername(req))
    return { success: true }
  }
}


