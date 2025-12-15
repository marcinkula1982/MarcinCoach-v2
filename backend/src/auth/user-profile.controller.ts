import {
  Body,
  Controller,
  Get,
  Put,
  Req,
  UseGuards,
} from '@nestjs/common'
import type { Request } from 'express'
import { SessionAuthGuard } from './session-auth.guard'
import { UserProfileService } from './user-profile.service'
import { GetProfileResponseDto, UpdateProfileDto } from './dto/profile.dto'

@Controller()
@UseGuards(SessionAuthGuard)
export class UserProfileController {
  constructor(private readonly profiles: UserProfileService) {}

  @Get('me/profile')
  async getMyProfile(
    @Req() req: Request & { authUser?: { userId: number } },
  ): Promise<GetProfileResponseDto> {
    const userId = req.authUser!.userId
    const profile = await this.profiles.getOrCreateProfile(userId)
    return profile
  }

  @Put('me/profile')
  async updateMyProfile(
    @Req() req: Request & { authUser?: { userId: number } },
    @Body() body: UpdateProfileDto,
  ): Promise<GetProfileResponseDto> {
    const userId = req.authUser!.userId
    const profile = await this.profiles.updateProfile(userId, body)
    return profile
  }
}


