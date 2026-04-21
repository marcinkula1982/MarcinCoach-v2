# PHP-only Cutover Checklist

> **Zakres cutoveru (Phase 1):** PHP core only — zgodnie z ADR 0002.
> AI (`/ai/plan`, `/ai/insights`) oraz integracje (`/integrations/strava/*`, `/integrations/garmin/*`)
> pozostają na Node.js do czasu M5/M6. Checklist weryfikuje wyłącznie endpointy dostępne w PHP.

## Goal
- Switch production API traffic to PHP backend for core coaching endpoints.
- Keep Node available for AI and integrations (Phase 1 dual-backend model).
- Keep Node available as rollback target for PHP core during rollback window.
- Cutover model: fresh start (`no user/session/data migration from Node`).
- M2 scope freeze before cutover: TCX-only parser/input path (`/workouts/upload`, `/workouts/import` with `source=tcx`).

## Ownership Assignment
- Go/No-Go Decision Owner: Marcin Kula — Project Owner
- Rollback Decision Owner: Marcin Kula — Project Owner
- Rollback Execution Owner: Marcin Kula — Project Owner
- App Validation Owner: Marcin Kula — Project Owner
- Communications Owner: Marcin Kula — Project Owner

Use the same names as in [docs/operations/cutover-roles-and-owners.md](docs/operations/cutover-roles-and-owners.md).

## Cold Start Acceptance (must be confirmed before go/no-go)
- New user with zero workouts receives a valid weekly plan based on profile heuristics.
- System does not crash or return 500 for zero-history user on `/api/weekly-plan`.
- Fallback behaviour is documented and tested (see `tests/Feature/Api/ColdStartTest.php`).
- `CONFIRMED` — cold start scenario covered by automated test.

## Go/No-Go Gate (must be all PASS)
- `PASS` if `php artisan test` is green in the cutover candidate.
- `PASS` if auth/session smoke pre-check passes (`register/login/me/profile/logout`).
- `PASS` if health endpoint `GET /api/health` returns 200 in target environment.
- `PASS` if cold start test passes (`ColdStartTest::test_new_user_with_no_workouts_gets_valid_weekly_plan`).
- `FAIL` on any item above; do not switch traffic until resolved.

> Note: Strava/Garmin sync entrypoints are NOT part of the PHP go/no-go gate.
> They remain on Node.js in Phase 1. See ADR 0002.

## User Communication (mandatory)
- Existing Node account/session does not work after cutover.
- User must register or log in again in PHP.
- User must complete onboarding profile again (`/me/profile`).
- AI plan features temporarily served from Node.js (no user-visible change expected).
- Send message before and at cutover window start; keep pinned for first 24h.

## Rollback Triggers (immediate escalation)
- Sustained `5xx` increase above normal baseline for 10 minutes.
- Auth/session failure wave (`401/403`) inconsistent with expected relogin behavior.
- Smoke sequence failure at any timed phase (`T+5m`, `T+30m`, `T+2h`, `T+24h`).
- Critical endpoint outage (`/api/health`, `/api/auth/login`, `/api/me/profile`, `/api/workouts/import`).

If any trigger is met, execute [docs/runbooks/php-only-rollback-runbook.md](docs/runbooks/php-only-rollback-runbook.md).

## Cutover Execution
- Route all API traffic for PHP core endpoints to PHP backend.
- Node.js remains active for AI and integrations (Phase 1 model).
- Keep Node deployment warm as rollback target for PHP core during rollback window.
- Start timed smoke phases immediately after switch.

## Timed Smoke Phases

### T+5m (critical path — PHP core only)
- `POST /auth/register` -> 200/201 with `sessionToken`.
- `POST /auth/login` -> 200 with `sessionToken`.
- `GET /me` with valid headers -> 200.
- `GET /me/profile` without token -> 401.
- `POST /auth/logout` then old token on `/me/profile` -> 401.
- `GET /api/health` -> 200.

Pass/Fail:
- `PASS` if all checks succeed.
- `FAIL` if any critical check fails; trigger rollback decision.

### T+30m (core product APIs — PHP only)
- `POST /workouts/import` with valid TCX -> 200/201, workout saved.
- `GET /workouts` -> 200, list returned.
- `GET /workouts/{id}` -> 200, workout detail returned.
- `GET /training-signals` -> 200, valid shape.
- `GET /training-adjustments` -> 200, valid shape.
- `GET /weekly-plan` -> 200, valid shape with sessions array.
- `GET /training-context` -> 200, valid shape.

> AI and integration endpoints (`/ai/*`, `/integrations/*`) are served by Node.js
> in Phase 1 and are NOT verified in this smoke phase.

Pass/Fail:
- `PASS` if no blocker-level errors on PHP core endpoints.
- `FAIL` on repeated 5xx, broken auth propagation, or unusable core endpoint.

### T+2h (stability check)
- Error rates stable vs baseline.
- Auth failures trending down after relogin wave.
- Decision log (`laravel.log`) shows plan/adjustment decisions without errors.
- No hidden dependency on Node runtime appears for PHP core paths.

Pass/Fail:
- `PASS` if stable trend and no unresolved blocker.
- `FAIL` if degradation persists or worsens.

### T+24h (acceptance)
- 24h operational metrics within acceptable range.
- No outstanding cutover blockers.
- Decision recorded: either finalize Node shutdown plan (Phase 2: M5+M6) or extend rollback window.

Pass/Fail:
- `PASS` finalize cutover and proceed with Node decommission planning for Phase 2.
- `FAIL` keep rollback window open and execute mitigation/rollback as needed.

## Monitoring Table (first 24h)
| Signal | What to watch | Alert threshold | Action |
|---|---|---|---|
| API availability | `GET /api/health` | non-200 or timeout spikes | escalate immediately, verify routing |
| API errors | 4xx/5xx rates | sustained elevated 5xx for 10m | trigger rollback evaluation |
| Auth/session | `/auth/login`, `/me/profile` failures | unexpected 401/403 pattern | verify token flow, comms, decide rollback |
| Core write flow | `/workouts/import` failures | repeated failure bursts | investigate quickly, stop cutover if severe |
| Plan decisions | `laravel.log` for `[WeeklyPlan]` / `[TrainingAdjustments]` entries | errors or missing log entries | verify service wiring, check for exceptions |

Reference operational docs:
- [docs/operations/php-cutover-monitoring.md](docs/operations/php-cutover-monitoring.md)
- [docs/operations/cutover-roles-and-owners.md](docs/operations/cutover-roles-and-owners.md)
- [docs/operations/node-decommission-plan.md](docs/operations/node-decommission-plan.md)
- [docs/adr/0002-cutover-scope-php-core-node-ai.md](docs/adr/0002-cutover-scope-php-core-node-ai.md)

## Post-cutover
- Observe and log status at `T+5m`, `T+30m`, `T+2h`, `T+24h`.
- Keep Node rollback window open until explicit sign-off.
- After T+24h sign-off: Node decommission planning begins for Phase 2 (M5 integrations, M6 AI migration).
