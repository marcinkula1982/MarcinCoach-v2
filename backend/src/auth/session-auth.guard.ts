import {
  CanActivate,
  ExecutionContext,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common'
import { Request } from 'express'
import { AuthService } from './auth.service'

@Injectable()
export class SessionAuthGuard implements CanActivate {
  constructor(private readonly authService: AuthService) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const req = context.switchToHttp().getRequest<Request>()

    const tokenHeader = req.headers['x-session-token']
    const token =
      typeof tokenHeader === 'string'
        ? tokenHeader
        : Array.isArray(tokenHeader)
        ? tokenHeader[0]
        : undefined

    if (!token) {
      throw new UnauthorizedException('Missing session token')
    }

    const user = await this.authService.validateSession(token)
    if (!user) {
      throw new UnauthorizedException('Invalid session token')
    }

    ;(req as any).authUser = user
    return true
  }
}


