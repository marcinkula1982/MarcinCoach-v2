import {
  CanActivate,
  ExecutionContext,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common'
import type { Request } from 'express'
import { PrismaService } from '../prisma.service'

@Injectable()
export class SessionAuthGuard implements CanActivate {
  private readonly IDLE_MS = 24 * 60 * 60 * 1000

  constructor(private prisma: PrismaService) {}

  async canActivate(ctx: ExecutionContext): Promise<boolean> {
    const req = ctx.switchToHttp().getRequest<Request & { authUser?: any }>()

    const token = req.header('x-session-token') || ''
    const username = req.header('x-username') || ''

    if (!token || !username) {
      throw new UnauthorizedException('MISSING_SESSION_HEADERS')
    }

    const session = await this.prisma.session.findUnique({
      where: { token },
      include: { user: true },
    })

    if (!session) {
      throw new UnauthorizedException('INVALID_SESSION')
    }

    if (session.user.username !== username) {
      throw new UnauthorizedException('SESSION_USER_MISMATCH')
    }

    const last = session.lastSeenAt ?? session.createdAt
    if (Date.now() - last.getTime() > this.IDLE_MS) {
      await this.prisma.session.delete({ where: { token } }).catch(() => {})
      throw new UnauthorizedException('SESSION_EXPIRED')
    }

    await this.prisma.session.update({
      where: { token },
      data: { lastSeenAt: new Date() },
    })

    req.authUser = {
      username: session.user.username,
      userId: session.user.userId,
    }

    return true
  }
}
