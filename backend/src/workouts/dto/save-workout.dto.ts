import { IsNotEmpty, IsObject, IsOptional, IsString } from 'class-validator'
import {
  RaceMeta,
  SaveAction,
  SaveWorkoutPayload,
  WorkoutKind,
  WorkoutSummary,
} from '../../types/workout.types'

export class SaveWorkoutDto implements Omit<SaveWorkoutPayload, 'userId'> {
  @IsString()
  @IsNotEmpty()
  tcxRaw!: string

  @IsString()
  @IsNotEmpty()
  action!: SaveAction

  @IsString()
  @IsNotEmpty()
  kind!: WorkoutKind

  @IsNotEmpty()
  summary!: WorkoutSummary

  @IsOptional()
  raceMeta?: RaceMeta

  @IsOptional()
  @IsObject()
  workoutMeta?: Record<string, any>
}



