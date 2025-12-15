import { Injectable, UnauthorizedException, BadRequestException } from '@nestjs/common'
import { PrismaService } from '../prisma.service'
import * as bcrypt from 'bcrypt'
import { randomUUID } from 'crypto'

@Injectable()
export class AuthService {
  private readonly IDLE_MS = 24 * 60 * 60 * 1000 // 24 godziny

  constructor(private prisma: PrismaService) {}

  async register(username: string, password: string) {
    if (!username || !password) {
      throw new BadRequestException('Username and password are required')
    }

    const existing = await this.prisma.authUser.findUnique({ where: { username } })
    if (existing) {
      throw new BadRequestException('User already exists')
    }

    const passwordHash = await bcrypt.hash(password, 10)

    // Znajdź lub utwórz domenowego Usera, ale linkuj WYŁĄCZNIE po ID
    const domainUser =
      (await this.prisma.user.findUnique({ where: { externalId: username } })) ??
      (await this.prisma.user.create({
        data: { externalId: username },
      }))

    const authUser = await this.prisma.authUser.create({
      data: { username, passwordHash, userId: domainUser.id },
    })

    return { success: true, userId: domainUser.id, authUserId: authUser.id }
  }

  async login(username: string, password: string) {
    const user = await this.prisma.authUser.findUnique({ where: { username } })
    if (!user) throw new UnauthorizedException('Invalid credentials')

    const ok = await bcrypt.compare(password, user.passwordHash)
    if (!ok) throw new UnauthorizedException('Invalid credentials')

    const token = randomUUID()

    await this.prisma.session.create({
      data: {
        token,
        authUserId: user.id,
        expiresAt: null,
        lastSeenAt: new Date(),
      },
    })

    return { sessionToken: token, username: user.username }
  }

  async validateSession(token: string) {
    const session = await this.prisma.session.findUnique({
      where: { token },
      include: { user: true },
    })

    if (!session) return null

    // Sprawdzenie wygaśnięcia
    const last = session.lastSeenAt ?? session.createdAt
    const now = Date.now()
    const lastTime = new Date(last).getTime()

    if (now - lastTime > this.IDLE_MS) {
      throw new UnauthorizedException('SESSION_EXPIRED')
    }

    // Sliding refresh - aktualizacja lastSeenAt
    await this.prisma.session.update({
      where: { id: session.id },
      data: { lastSeenAt: new Date() },
    })

    return session.user
  }
}


