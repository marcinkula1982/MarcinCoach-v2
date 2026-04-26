> HISTORYCZNE - nie uzywac jako aktualnej instrukcji.
> Aktywne dokumenty: `docs/status.md` (wykonane funkcjonalnosci), `docs/roadmap.md` (plan), `docs/deploy/frontend-iqhost-deploy.txt` (deploy frontu), `docs/integrations.md` (integracje).
# Audyt braków migracji Node -> PHP

Data audytu: 2026-04-20

## 1) API parity audit (Node endpointy bez pełnego odpowiednika w PHP)

Legenda owner:
- `BE-PHP` - implementacja po stronie Laravel
- `FE` - dostosowanie klienta `src/api`
- `BE-Node` - referencja kontraktu i reguł z Nest
- `QA` - testy parity i testy regresji

| Obszar | Endpoint Node | Status w PHP | Luka | Owner |
|---|---|---|---|---|
| auth | `POST /auth/register` | brak | brak pełnego auth flow | BE-PHP |
| auth | `POST /auth/login` | brak | frontend nie ma endpointu logowania | BE-PHP + FE |
| profile | `GET /me/profile` | brak | brak kontraktu profilu | BE-PHP |
| profile | `PUT /me/profile` | brak | brak zapisu profilu | BE-PHP |
| training | `GET /weekly-plan` | brak | tylko wewnętrzny serwis weekly plan | BE-PHP |
| training | `GET /training-context` | brak | brak publicznego endpointu kontekstu | BE-PHP |
| workouts | `GET /workouts` | brak | frontend nie może pobrać listy | BE-PHP + FE |
| workouts | `POST /workouts` | brak | brak zapisu treningu raw JSON | BE-PHP |
| workouts | `POST /workouts/upload` | brak | istnieje `POST /workouts/import`, inny kontrakt | BE-PHP + FE |
| workouts | `DELETE /workouts/:id` | brak | brak usuwania treningu | BE-PHP |
| workouts | `GET /workouts/analytics` | brak | brak API analytics | BE-PHP |
| workouts | `GET /workouts/analytics/rows` | brak | brak API analytics rows | BE-PHP |
| workouts | `GET /workouts/analytics/summary` | brak | brak API analytics summary | BE-PHP |
| workouts | `GET /workouts/analytics/summary-v2` | brak | brak API analytics summary-v2 | BE-PHP |
| system | `GET /` | brak | brak root endpointu zgodnego z Node | BE-PHP |

Referencje:
- Node endpointy: `backend/src/**/*.controller.ts`
- PHP endpointy: `backend-php/routes/api.php`

## 2) Frontend contract gaps (`src/api` -> PHP)

| Front API client | Oczekiwany endpoint | Obecny status PHP | Niezgodność kontraktu | Owner |
|---|---|---|---|---|
| `src/api/auth.ts` | `POST /auth/login` | brak | brak endpointu | BE-PHP + FE |
| `src/api/profile.ts` | `GET/PUT /me/profile` | brak | brak endpointów i shape profilu | BE-PHP |
| `src/api/workouts.ts` | `GET /workouts` | brak | brak listy | BE-PHP |
| `src/api/workouts.ts` | `POST /workouts/upload` | brak (jest `/workouts/import`) | inna ścieżka i body (multipart vs JSON) | BE-PHP + FE |
| `src/api/workouts.ts` | `DELETE /workouts/:id` | brak | brak endpointu | BE-PHP |
| `src/api/workouts.ts` | `GET /weekly-plan` | brak | brak endpointu | BE-PHP |
| `src/api/workouts.ts` | `GET /workouts/:id?includeRaw=true` | częściowo | PHP `show` nie zwraca pełnego `summary/workoutMeta/tcxRaw` jak oczekuje UI | BE-PHP + FE |

Uwagi:
- `src/api/ai-insights.ts` jest już zgodne z PHP (`{ payload, cache }`).
- `src/api/ai-plan.ts` wymaga doprecyzowania typu `provider`, bo backend może zwrócić `cache`.

## 3) Domain parity gaps (częściowe porty)

| Moduł | Node (referencja) | PHP (referencja) | Status | Zakres braków |
|---|---|---|---|---|
| AI rate limit | `backend/src/ai-rate-limit/*` | brak | brak | brak guard/service/store i nagłówków limitów |
| Plan snapshot | `backend/src/plan-snapshot/*` | brak | brak | brak zapisu snapshotów planu po generacji |
| Feedback v2 generator | `backend/src/training-feedback-v2/training-feedback-v2.service.ts` | `backend-php/app/Services/TrainingFeedbackV2Service.php` | częściowy | PHP ma slim pipeline i uproszczoną logikę |
| Weekly plan | `backend/src/weekly-plan/weekly-plan.service.ts` | `backend-php/app/Services/WeeklyPlanService.php` | częściowy | podzbiór reguł i transformacji |
| Training signals | `backend/src/training-signals/training-signals.service.ts` | `backend-php/app/Services/TrainingSignalsService.php` | częściowy | różnica shape payloadu i semantyki |

## 4) Plan rozszerzenia testów parity

### Braki testowe (dzisiaj)
- Node-only: granularne testy `ai-rate-limit`, `training-context`, `weekly-plan`, reguł `feedback-v2`.
- PHP-only: szerokie feature testy workout/import/backfill bez pełnego odpowiednika w Node.
- Cross-stack skrypt `scripts/e2e-cross-stack.mjs` obejmuje tylko część kontraktu.

### Docelowy zakres parity (P2)
1. Dodać porównania cross-stack dla:
   - `ai/insights`
   - `ai/plan`
   - `training-feedback-v2` (`generate`, `signals`, `question`)
   - `workouts` (`list`, `show`, `upload/import`, `delete`)
   - `profile` (`get/update`)
   - `auth/login` (jeśli utrzymujemy ten flow)
2. Dodać testy kontraktowe request/response (shape assertions) po obu stronach.
3. Dodać smoke test FE API against PHP dla krytycznych flow.

## Priorytety wdrożenia (zgodne z planem)

### P0 - runtime blockery frontu
- Auth + profile + workouts parity.
- Adapter decyzji: czy utrzymujemy `/workouts/upload`, czy migrujemy FE na `/workouts/import`.
- Ujednolicenie `GET /workouts/:id` contract.

### P1 - feature parity
- `GET /weekly-plan`.
- `GET /training-context`.
- Domknięcie pełniejszego generatora `training-feedback-v2`.

### P1/P2 - stabilność produkcyjna
- AI rate-limit po stronie PHP.
- Plan snapshot po stronie PHP.

### P2 - parity test coverage
- Poszerzenie `scripts/e2e-cross-stack.mjs` o pełen zestaw endpointów.

