import { IsEnum, IsNotEmpty, IsObject, IsOptional, IsString } from 'class-validator'
import type { WorkoutSummary } from '../../types/workout.types'

export enum WorkoutSourceDto {
  MANUAL_UPLOAD = 'MANUAL_UPLOAD',
  GARMIN = 'GARMIN',
  STRAVA = 'STRAVA',
}

export class ImportWorkoutDto {
  @IsEnum(WorkoutSourceDto)
  @IsNotEmpty()
  source!: WorkoutSourceDto

  @IsString()
  @IsOptional()
  sourceActivityId?: string | null

  @IsString()
  @IsOptional()
  sourceUserId?: string | null

  @IsString()
  @IsNotEmpty()
  startTimeIso!: string

  @IsString()
  @IsOptional()
  tcxRaw?: string | null

  @IsString()
  @IsOptional()
  fitRaw?: string | null

  @IsObject()
  @IsNotEmpty()
  summary!: WorkoutSummary

  @IsObject()
  @IsOptional()
  workoutMeta?: Record<string, any> | null
}


