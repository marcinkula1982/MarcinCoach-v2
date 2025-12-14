# Przeniesienie logiki idle timeout do SessionAuthGuard

## Cel
Przeniesienie logiki sprawdzania wygaśnięcia sesji (idle timeout) i sliding refresh bezpośrednio do `SessionAuthGuard`, zamiast używać `AuthService.validateSession()`. To upraszcza architekturę i umożliwia dodatkowe walidacje (np. sprawdzanie zgodności username z tokenem).

## Zmiany w plikach

### 1. Aktualizacja `backend/src/auth/session-auth.guard.ts`
- **Zastąpienie zależności**: zmiana z `AuthService` na `PrismaService`
- **Dodanie stałej**: `IDLE_MS = 24 * 60 * 60 * 1000`
- **Implementacja logiki bezpośrednio w `canActivate()`**:
  - Pobranie tokenu z nagłówka `x-session-token`
  - Pobranie username z nagłówka `x-user-id`
  - Walidacja obecności obu nagłówków → `MISSING_SESSION_HEADERS`
  - Pobranie sesji z bazy (z include user)
  - Sprawdzenie istnienia sesji → `INVALID_SESSION`
  - Sprawdzenie zgodności username z sesją → `SESSION_USER_MISMATCH`
  - Sprawdzenie wygaśnięcia (24h od `lastSeenAt ?? createdAt`)
  - Jeśli wygasła → usunięcie sesji + `SESSION_EXPIRED`
  - Sliding refresh: aktualizacja `lastSeenAt`
  - Ustawienie `req.authUser = { username, userId }`
  - Zwrócenie `true`

### 2. Aktualizacja `backend/src/auth/auth.module.ts`
- **Dodanie `PrismaService` do providers** w `AuthModule` (jeśli jeszcze nie ma)
- Upewnienie się, że `SessionAuthGuard` ma dostęp do `PrismaService`

### 3. Opcjonalnie: `backend/src/auth/auth.service.ts`
- **Uwaga**: Metoda `validateSession()` w `AuthService` może pozostać (dla kompatybilności), ale `SessionAuthGuard` nie będzie już z niej korzystał
- Można ją usunąć lub pozostawić jako deprecated (decyzja użytkownika)

## Szczegóły implementacji

### Struktura kodu w guardzie
```typescript
@Injectable()
export class SessionAuthGuard implements CanActivate {
  private readonly IDLE_MS = 24 * 60 * 60 * 1000 // 24h

  constructor(private prisma: PrismaService) {}

  async canActivate(ctx: ExecutionContext): Promise<boolean> {
    const req = ctx.switchToHttp().getRequest<Request & { authUser?: any }>()

    const token = req.header('x-session-token') || ''
    const username = req.header('x-user-id') || ''

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

    const now = Date.now()
    const last = (session.lastSeenAt ?? session.createdAt).getTime()

    if (now - last > this.IDLE_MS) {
      await this.prisma.session.delete({ where: { token } }).catch(() => {})
      throw new UnauthorizedException('SESSION_EXPIRED')
    }

    await this.prisma.session.update({
      where: { token },
      data: { lastSeenAt: new Date() },
    })

    req.authUser = { username: session.user.username, userId: session.userId }
    return true
  }
}
```

### Uwagi techniczne
- **Ścieżka do PrismaService**: W kodzie użytkownika jest `../prisma/prisma.service`, ale faktyczna ścieżka to `../prisma.service` - należy to poprawić
- **Nagłówek `x-user-id`**: Guard wymaga teraz zarówno `x-session-token` jak i `x-user-id` (username)
- **Usuwanie wygasłych sesji**: Gdy sesja wygasa, jest automatycznie usuwana z bazy
- **Sliding refresh**: `lastSeenAt` jest aktualizowane przy każdym poprawnym requeście
- **Walidacja zgodności**: Sprawdzanie czy username z nagłówka pasuje do sesji zwiększa bezpieczeństwo

## Weryfikacja
- `SessionAuthGuard` używa `PrismaService` zamiast `AuthService`
- Logika idle timeout jest w guardzie
- Guard sprawdza obecność obu nagłówków (`x-session-token`, `x-user-id`)
- Guard sprawdza zgodność username z sesją
- Guard usuwa wygasłe sesje z bazy
- Guard aktualizuje `lastSeenAt` przy każdym requeście
- `AuthModule` eksportuje `PrismaService` (lub ma go w providers)

