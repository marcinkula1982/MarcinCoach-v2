# Implementacja idle timeout dla sesji (24h)

## Cel
Dodanie mechanizmu wygasania sesji po 24 godzinach bezczynności (idle timeout). Przy każdym poprawnym requeście sesja jest odświeżana (sliding refresh).

## Założenia MVP
- `idleTimeout = 24h` (24 * 60 * 60 * 1000 ms)
- Przy każdym poprawnym requeście: aktualizacja `lastSeenAt`
- Jeśli minęło >24h od ostatniej aktywności → `401 Unauthorized` + komunikat "SESSION_EXPIRED"

## Zmiany w plikach

### 1. Aktualizacja schematu Prisma: `backend/prisma/schema.prisma`
- **Dodanie pola `lastSeenAt`** do modelu `Session`:
  ```prisma
  model Session {
    id         Int       @id @default(autoincrement())
    token     String    @unique
    userId    Int
    user      AuthUser  @relation(fields: [userId], references: [id])
    createdAt DateTime  @default(now())
    expiresAt DateTime?
    lastSeenAt DateTime?  // <-- DODAJ TO
  }
  ```

### 2. Migracja bazy danych
- Utworzenie migracji Prisma:
  ```bash
  cd backend
  npx prisma migrate dev --name add_last_seen_at_to_session
  npx prisma generate
  ```

### 3. Aktualizacja `backend/src/auth/auth.service.ts`
- **Modyfikacja metody `validateSession()`**:
  - Dodanie stałej `IDLE_MS = 24 * 60 * 60 * 1000`
  - Po znalezieniu sesji w DB:
    1. Sprawdzenie wygaśnięcia:
       - Pobranie `lastSeenAt ?? createdAt` jako punktu odniesienia
       - Obliczenie: `Date.now() - new Date(last).getTime() > IDLE_MS`
       - Jeśli wygasła → `throw new UnauthorizedException('SESSION_EXPIRED')`
    2. Sliding refresh (aktualizacja `lastSeenAt`):
       - Jeśli sesja jest ważna → `await this.prisma.session.update({ where: { id: session.id }, data: { lastSeenAt: new Date() } })`
  - Zwrócenie użytkownika tylko jeśli sesja jest ważna

- **Opcjonalnie: aktualizacja metody `login()`**:
  - Ustawienie `lastSeenAt: new Date()` przy tworzeniu nowej sesji (dla spójności)

## Szczegóły implementacji

### Logika sprawdzania wygaśnięcia
```typescript
const IDLE_MS = 24 * 60 * 60 * 1000 // 24 godziny

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
  
  if (now - lastTime > IDLE_MS) {
    throw new UnauthorizedException('SESSION_EXPIRED')
  }

  // Sliding refresh - aktualizacja lastSeenAt
  await this.prisma.session.update({
    where: { id: session.id },
    data: { lastSeenAt: new Date() },
  })

  return session.user
}
```

### Obsługa błędów
- `UnauthorizedException('SESSION_EXPIRED')` jest rzucane gdy sesja wygasła
- Frontend powinien obsłużyć `401` i przekierować do logowania

### Uwagi techniczne
- `lastSeenAt` jest opcjonalne (`DateTime?`) dla kompatybilności wstecznej z istniejącymi sesjami
- Dla istniejących sesji bez `lastSeenAt` używamy `createdAt` jako punktu odniesienia
- Aktualizacja `lastSeenAt` następuje **po** sprawdzeniu ważności, ale **przed** zwróceniem użytkownika
- Mechanizm działa automatycznie dla wszystkich endpointów chronionych przez `SessionAuthGuard`

## Weryfikacja
- Pole `lastSeenAt` zostało dodane do modelu `Session` w Prisma
- Migracja została utworzona i zastosowana
- `validateSession()` sprawdza wygaśnięcie i rzuca `UnauthorizedException('SESSION_EXPIRED')` gdy minęło >24h
- `lastSeenAt` jest aktualizowane przy każdym poprawnym requeście
- Endpointy chronione przez `SessionAuthGuard` automatycznie korzystają z nowej logiki

