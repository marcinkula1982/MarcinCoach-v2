import { IsObject, IsOptional } from 'class-validator'

export class UpdateWorkoutMetaDto {
  @IsOptional()
  @IsObject()
  workoutMeta?: Record<string, any>
}

