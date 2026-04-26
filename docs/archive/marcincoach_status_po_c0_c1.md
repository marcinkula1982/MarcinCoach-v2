# MarcinCoach v2 — status po C0 i C1

> Data: 2026-04-21
> Pakiety: C0 (decyzja architektoniczna) + C1 (domknięcie luk go/no-go)
> Poprzedni stan: M1 beyond minimum, M2 beyond minimum, M3, M4 — domknięte
> Cel: zielone go/no-go dla cutoveru Phase 1 (PHP core)

---

## Co zostało zrobione

### C0 — Decyzja architektoniczna

**ADR 0002** (`docs/adr/0002-cutover-scope-php-core-node-ai.md`) — decyzja zapisana.

Zakres cutoveru Phase 1:
- PHP przejmuje: auth, profil, workouty, signals, context, adjustments, weekly plan, compliance, alerts
- Node.js pozostaje dla: `/ai/plan`, `/ai/insights`, `/integrations/strava/*`, `/integrations/garmin/*`

**Cutover checklist** (`docs/php-only-cutover-checklist.md`) — zaktualizowana:
- Właściciele uzupełnieni (wszystkie role: Marcin Kula)
- T+30m smoke: usunięte `/ai/insights`, `/ai/plan`, `/integrations/*` — zastąpione PHP core endpoints
- Dodana sekcja Cold Start Acceptance jako gate go/no-go
- Dodany monitoring `laravel.log` dla decyzji plan/adaptation

---

### C1 — Domknięcie luk go/no-go

**1. Contract freeze testy** (`tests/Feature/Api/ContractFreezeTest.php`) — nowy plik, 18 testów:
- Weryfikacja top-level shape: weekly-plan, training-signals, training-adjustments, training-context
- Weryfikacja zakazanych pól: `techniqueFocus`, `surfaceHint` (M3 drift), `adaptation` (M4 drift)
- Weryfikacja `windowDays` query param dla wszystkich 4 endpointów
- Weryfikacja shape sub-obiektów: buckets, flags, longRun, summary, sessions

**2. Cold start testy** (`tests/Feature/Api/ColdStartTest.php`) — nowy plik, 5 testów:
- `test_new_user_with_no_workouts_gets_valid_weekly_plan` — gate cutoveru
- Zero workouts → signals zero-values, flags false
- Zero workouts → brak quality sessions (canQuality = false)
- Zero workouts → plan nie crashuje
- `maxSessionMin` cap działa nawet bez historii treningowej

**3. Application-level logging** — nowe `Log::info()` w:
- `WeeklyPlanService::generatePlan()` — log `[WeeklyPlan] generated` z userId, signals summary, output (session types, adjustment codes, totalDurationMin)
- `TrainingAdjustmentsService::generate()` — log `[TrainingAdjustments] generated` z signals input i output codes

**4. Mini-audit M1–M4** (`docs/audit/m1-m4-pre-cutover-audit.md`) — dokument wynikowy:
- Weryfikacja obecności kluczowych serwisów, migracji, testów dla M1–M4
- Potwierdzenie: brak krytycznych regresji, brak nieudokumentowanego contract driftu
- Udokumentowane długi techniczne (non-blocking)
- Wynik: PASS dla celów cutoveru Phase 1

**5. Właściciele w dokumentach operacyjnych** — uzupełnione:
- `docs/runbooks/php-only-rollback-runbook.md` — wszystkie role: Marcin Kula
- `docs/operations/cutover-roles-and-owners.md` — wszystkie role: Marcin Kula

**6. Sekcja o danych z okna cutoveru** — dodana do `docs/runbooks/php-only-rollback-runbook.md`:
- Decyzja: dane zapisane w PHP podczas okna cutoveru NIE są migrowane po rollbacku
- Co komunikować użytkownikom po rollbacku
- Co utrwalić przed rollbackiem (liczba kont, workoutów, timestamp)

---

## Status go/no-go po C0+C1

| # | Punkt | Przed C0/C1 | Po C0/C1 |
|---|-------|-------------|----------|
| 1 | Kontrakty publiczne stabilne | AMBER | ✅ ZIELONY — contract freeze testy pokrywają 4 endpointy |
| 2 | M1–M4 zamknięte audytem | RED | ✅ ZIELONY — docs/audit/m1-m4-pre-cutover-audit.md |
| 3 | Rollback opisany technicznie | AMBER | ✅ ZIELONY — właściciele uzupełnieni, dane z okna cutoveru zaadresowane |
| 4 | Obserwowalność po cutoverze | RED | ✅ ZIELONY — Log::info() w WeeklyPlanService i TrainingAdjustmentsService, failed_jobs aktywne |
| 5 | Cold start zaakceptowany | RED | ✅ ZIELONY — ColdStartTest.php, fallback udokumentowany |
| 6 | Jedno źródło prawdy | RED | ✅ ZIELONY — ADR 0002 definiuje zakres, checklist naprawiony |
| 7 | Świadoma decyzja biznesowa | RED | ✅ ZIELONY — ADR 0002 zapisany i zaakceptowany |

**Wszystkie 7 punktów: ZIELONE.**

---

## Co pozostaje do M9 (cutover execution)

**`php artisan test` — 220 passed, 1023 assertions (2026-04-21). Zielony.**
Wszystkie testy przeszły po naprawieniu drobnej regresji loggingu (try/catch w `WeeklyPlanService` i `TrainingAdjustmentsService` — Log facade wymaga kontenera, który nie jest dostępny w Unit testach instancjonujących serwis bezpośrednio).

Jedyne co pozostaje przed M9: **fizyczne przekierowanie ruchu** — implementacja zależy od stacku deployment (nginx/load balancer/proxy). To krok infrastrukturalny po stronie Project Ownera.

Wykonać checklist M9 zgodnie z `docs/php-only-cutover-checklist.md`.

---

## Pliki zmienione lub utworzone

### Nowe pliki
| Plik | Typ | Opis |
|------|-----|------|
| `docs/adr/0002-cutover-scope-php-core-node-ai.md` | ADR | Decyzja architektoniczna zakresu cutoveru Phase 1 |
| `docs/audit/m1-m4-pre-cutover-audit.md` | Audit | Pre-cutover weryfikacja M1–M4 |
| `backend-php/tests/Feature/Api/ContractFreezeTest.php` | Test | 18 testów freeze shape 4 endpointów |
| `backend-php/tests/Feature/Api/ColdStartTest.php` | Test | 5 testów cold start (nowy user, zero workoutów) |

### Zmienione pliki
| Plik | Zmiana |
|------|--------|
| `docs/php-only-cutover-checklist.md` | Właściciele, cold start gate, T+30m bez AI/integracji, monitoring plan log |
| `docs/runbooks/php-only-rollback-runbook.md` | Właściciele, sekcja danych z okna cutoveru |
| `docs/operations/cutover-roles-and-owners.md` | Właściciele |
| `backend-php/app/Services/WeeklyPlanService.php` | Log::info('[WeeklyPlan] generated') |
| `backend-php/app/Services/TrainingAdjustmentsService.php` | Log::info('[TrainingAdjustments] generated') |

---

## Czego ten plik NIE oznacza

Ten plik nie oznacza, że cutover został wykonany.
Oznacza, że wszystkie 7 punktów go/no-go jest zielonych i projekt jest gotowy do M9 (wykonanie cutoveru) po uruchomieniu testów na docelowym środowisku.

---

## Następny krok

**M9 — Cutover execution.**

Wykonać zgodnie z `docs/php-only-cutover-checklist.md`:
1. `php artisan test` → PASS
2. Go/No-Go gate → PASS
3. Przełączenie ruchu
4. Smoke T+5m → T+30m → T+2h → T+24h
5. Sign-off lub rollback

Po T+24h sign-off: planowanie Phase 2 (M5 integracje, M6 AI migration do PHP).
