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
  private readonly IDLE_MS = 24 * 60 * 60 * 1000 // 24h

  constructor(private prisma: PrismaService) {}

  async canActivate(ctx: ExecutionContext): Promise<boolean> {
    const req = ctx.switchToHttp().getRequest<Request & { authUser?: any }>()

    const token = req.header('x-session-token') || ''
    const username = req.header('x-user-id') || '' // u Ciebie to username

    if (!token || !username) {
      throw new UnauthorizedException('MISSING_SESSION_HEADERS')
    }

    const session = await this.prisma.session.findUnique({
      where: { token },
      include: { user: true }, // AuthUser
    })

    if (!session) {
      throw new UnauthorizedException('INVALID_SESSION')
    }

    // (opcjonalnie, ale warto) token musi należeć do tego username
    if (session.user.username !== username) {
      throw new UnauthorizedException('SESSION_USER_MISMATCH')
    }

    const now = Date.now()
    const last = (session.lastSeenAt ?? session.createdAt).getTime()

    if (now - last > this.IDLE_MS) {
      // porządek: kasujemy przeterminowaną sesję
      await this.prisma.session.delete({ where: { token } }).catch(() => {})
      throw new UnauthorizedException('SESSION_EXPIRED')
    }

    // sliding refresh
    await this.prisma.session.update({
      where: { token },
      data: { lastSeenAt: new Date() },
    })

    req.authUser = { username: session.user.username, userId: session.userId }
    return true
  }
}


