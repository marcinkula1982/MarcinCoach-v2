---
name: AI daily rate limit
overview: Dodać wspólny (dla /ai/plan i /ai/insights) rate limiting per user per UTC-day w NestJS poprzez Guard + in-memory Map oraz testy z wstrzykniętym zegarem (bez Date.now wprost).
todos:
  - id: add-rate-limit-module
    content: Dodać AiRateLimitModule + Clock + AiDailyRateLimitService (in-memory Map + cleanup)
    status: completed
  - id: add-rate-limit-guard
    content: Dodać AiDailyRateLimitGuard zwracający 429 z wymaganym JSON
    status: completed
    dependencies:
      - add-rate-limit-module
  - id: wire-controllers
    content: Podpiąć guard do /ai/plan i /ai/insights oraz zapewnić jeden wspólny store (global module lub single import)
    status: completed
    dependencies:
      - add-rate-limit-guard
  - id: tests
    content: "Dodać testy (e2e lub controller/service) z fake Clock: sumowanie limitu, 429, reset następnego dnia"
    status: completed
    dependencies:
      - wire-controllers
  - id: report-files
    content: Wypisać listę zmienionych plików po wdrożeniu
    status: completed
    dependencies:
      - tests
---

# Rate limiting AI (in-memory, per UTC-day)

## Cel

Wdrożyć **wspólny limit wywołań AI** (sumowany łącznie dla `GET /ai/plan` i `GET /ai/insights`) w backendzie NestJS, per `userId` i per **UTC-day**.

## Wymagania (Twoje)

- Klucz limitu: `userId + yyyy-mm-dd` (UTC)
- Limit sumuje się między endpointami `/ai/plan` i `/ai/insights`
- Env:
- `AI_DAILY_CALL_LIMIT_PROD=25`
- `AI_DAILY_CALL_LIMIT_DEV=250`
- Po przekroczeniu: HTTP **429** i JSON:
- `{ "statusCode": 429, "message": "AI daily limit exceeded", "limit": <n>, "used": <n>, "resetAtIso": "<UTC midnight next day>" }`
- Bez zewnętrznych bibliotek; wystarczy **in-memory `Map`** + proste czyszczenie starych dni
- Testy: potwierdzenie sumowania, 429 po przekroczeniu, reset następnego dnia
- Testy muszą używać wstrzykniętego zegara (bez `Date.now()`/`new Date()` wprost w logice limitera)
- Nie zmieniamy kontraktów sukcesu `/ai/plan` ani `/ai/insights`

## Podejście

### 1) Wspólny serwis limitów + zegar

- Dodamy `Clock` (provider) z metodą `now(): Date`
- `AiDailyRateLimitService`:
- oblicza `dayKeyUtc = YYYY-MM-DD` i `resetAtIso` (następna północ UTC)
- trzyma `Map<string, number>` gdzie key = `${userId}:${dayKeyUtc}`
- inkrementuje count i sprawdza limit
- okresowo czyści stare dni (np. raz na X minut lub co N requestów)

### 2) Guard na controllerach AI

- Dodamy `AiDailyRateLimitGuard` i podepniemy go obok `SessionAuthGuard` na:
- [`backend/src/ai-plan/ai-plan.controller.ts`](backend/src/ai-plan/ai-plan.controller.ts)
- [`backend/src/ai-insights/ai-insights.controller.ts`](backend/src/ai-insights/ai-insights.controller.ts)
- Guard będzie pobierał `userId` z `req.authUser.userId` (już ustawiane przez `SessionAuthGuard`)

### 3) Konfiguracja limitów DEV/PROD

- `AiDailyRateLimitService` wybierze limit na podstawie `NODE_ENV`:
- `production` → `AI_DAILY_CALL_LIMIT_PROD` (default 25)
- inaczej → `AI_DAILY_CALL_LIMIT_DEV` (default 250)

### 4) Testy

Dodamy testy (preferowane e2e z `supertest`), które:

- wykonują N wywołań mieszanych: `/ai/plan` i `/ai/insights` → limit zużywa się łącznie
- na `(limit+1)` dostają 429 i poprawny JSON (w tym `resetAtIso`)
- „następny dzień” symulujemy przez podmianę `Clock` w module testowym

Żeby testy nie dotykały realnych zależności (OpenAI/DB), podmienimy serwisy `AiPlanService`/`AiInsightsService` na stuby zwracające stały payload (kontrakt sukcesu bez zmian).

## Pliki

- Nowe:
- [`backend/src/ai-rate-limit/clock.ts`](backend/src/ai-rate-limit/clock.ts)
- [`backend/src/ai-rate-limit/ai-daily-rate-limit.service.ts`](backend/src/ai-rate-limit/ai-daily-rate-limit.service.ts)
- [`backend/src/ai-rate-limit/ai-daily-rate-limit.guard.ts`](backend/src/ai-rate-limit/ai-daily-rate-limit.guard.ts)
- [`backend/src/ai-rate-limit/ai-rate-limit.module.ts`](backend/src/ai-rate-limit/ai-rate-limit.module.ts) (najpewniej `@Global()` żeby jeden store działał dla obu endpointów)
- test: np. [`backend/test/ai-rate-limit.e2e-spec.ts`](backend/test/ai-rate-limit.e2e-spec.ts)
- Zmiany:
- [`backend/src/ai-plan/ai-plan.controller.ts`](backend/src/ai-plan/ai-plan.controller.ts) (dodać guard)
- [`backend/src/ai-insights/ai-insights.controller.ts`](backend/src/ai-insights/ai-insights.controller.ts) (dodać guard)
- [`backend/src/ai-plan/ai-plan.module.ts`](backend/src/ai-plan/ai-plan.module.ts) + [`backend/src/ai-insights/ai-insights.module.ts`](backend/src/ai-insights/ai-insights.module.ts) lub [`backend/src/app.module.ts`](backend/src/app.module.ts) (import modułu limitera)

## Kryteria akceptacji

- Limity sumują się między `/ai/plan` i `/ai/insights`
- 429 po przekroczeniu + JSON zgodny z wymaganiem
- Reset działa następnego dnia (test przez fake clock)
- Brak zmian w payloadach sukcesu obu endpointów