import { IsOptional, IsString } from 'class-validator'

export class GetProfileResponseDto {
  id!: number
  userId!: number
  preferredRunDays: string | null
  preferredSurface: string | null
  goals: string | null
  constraints: string | null
  createdAt: Date
  updatedAt: Date
}

export class UpdateProfileDto {
  @IsOptional()
  @IsString()
  preferredRunDays?: string

  @IsOptional()
  @IsString()
  preferredSurface?: string

  @IsOptional()
  @IsString()
  goals?: string

  @IsOptional()
  @IsString()
  constraints?: string
}

