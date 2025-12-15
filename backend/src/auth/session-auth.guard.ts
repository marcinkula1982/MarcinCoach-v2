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

    if (!token) {
      throw new UnauthorizedException('MISSING_SESSION_HEADERS')
    }

    const session = await this.prisma.session.findUnique({
      where: { token },
      include: { user: true }, // AuthUser (linked to canonical User via userId)
    })

    if (!session) {
      throw new UnauthorizedException('INVALID_SESSION')
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

    const authUser: any = session.user
    req.authUser = {
      authUserId: authUser.id,
      userId: authUser.userId,
      username: authUser.username,
    }
    return true
  }
}


