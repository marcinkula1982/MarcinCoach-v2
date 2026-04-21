# PHP-only Cutover Checklist

## Goal
- Switch production API traffic to PHP backend only.
- Keep Node available only during rollback window.
- Cutover model: fresh start (`no user/session/data migration from Node`).
- M2 scope freeze before cutover: TCX-only parser/input path (`/workouts/upload`, `/workouts/import` with `source=tcx`).

## Ownership Assignment (fill before cutover)
- Go/No-Go Decision Owner: `[TO BE ASSIGNED: Go/No-Go Decision Owner]` (Marcin Kula — Project Owner)
- Rollback Decision Owner: `[TO BE ASSIGNED: Rollback Decision Owner]` (Marcin Kula — Project Owner)
- Rollback Execution Owner: `[TO BE ASSIGNED: Rollback Execution Owner]` (Marcin Kula — Project Owner)
- App Validation Owner: `[TO BE ASSIGNED: App Validation Owner]` (Marcin Kula — Project Owner)
- Communications Owner: `[TO BE ASSIGNED: Communications Owner]` (Marcin Kula — Project Owner)

Use the same names as in [docs/operations/cutover-roles-and-owners.md](docs/operations/cutover-roles-and-owners.md).

## Go/No-Go Gate (must be all PASS)
- `PASS` if `php artisan test` is green in the cutover candidate.
- `PASS` if auth/session smoke pre-check passes (`register/login/me/profile/logout`).
- `PASS` if integrations smoke pre-check passes (`strava`, `garmin` sync entrypoints reachable).
- `PASS` if health endpoint `GET /api/health` returns 200 in target environment.
- `FAIL` on any item above; do not switch traffic until resolved.

## User Communication (mandatory)
- Existing Node account/session does not work after cutover.
- User must register or log in again in PHP.
- User must complete onboarding profile again (`/me/profile`).
- Send message before and at cutover window start; keep pinned for first 24h.

## Rollback Triggers (immediate escalation)
- Sustained `5xx` increase above normal baseline for 10 minutes.
- Auth/session failure wave (`401/403`) inconsistent with expected relogin behavior.
- Smoke sequence failure at any timed phase (`T+5m`, `T+30m`, `T+2h`, `T+24h`).
- Critical endpoint outage (`/api/health`, `/api/auth/login`, `/api/me/profile`, `/api/workouts/import`).

If any trigger is met, execute [docs/runbooks/php-only-rollback-runbook.md](docs/runbooks/php-only-rollback-runbook.md).

## Cutover Execution
- Route all API traffic to PHP backend.
- Keep Node deployment warm during rollback window.
- Start timed smoke phases immediately after switch.

## Timed Smoke Phases

### T+5m (critical path)
- `POST /auth/register` -> 200/201 with `sessionToken`.
- `POST /auth/login` -> 200 with `sessionToken`.
- `GET /me` with valid headers -> 200.
- `GET /me/profile` without token -> 401.
- `POST /auth/logout` then old token on `/me/profile` -> 401.
- `GET /api/health` -> 200.

Pass/Fail:
- `PASS` if all checks succeed.
- `FAIL` if any critical check fails; trigger rollback decision.

### T+30m (core product APIs)
- `/workouts/import`, `/workouts`, `/workouts/{id}` -> healthy responses.
- `/training-signals`, `/training-adjustments` -> healthy responses.
- `/ai/insights`, `/ai/plan` -> healthy responses.
- `/integrations/strava/*`, `/integrations/garmin/*` -> entrypoints healthy.

Pass/Fail:
- `PASS` if no blocker-level errors.
- `FAIL` on repeated 5xx, broken auth propagation, or unusable core endpoint.

### T+2h (stability check)
- Error rates stable vs baseline.
- Auth failures trending down after relogin wave.
- Background sync jobs continue processing.
- No hidden dependency on Node runtime appears.

Pass/Fail:
- `PASS` if stable trend and no unresolved blocker.
- `FAIL` if degradation persists or worsens.

### T+24h (acceptance)
- 24h operational metrics within acceptable range.
- No outstanding cutover blockers.
- Decision recorded: either finalize Node shutdown plan or extend rollback window.

Pass/Fail:
- `PASS` finalize cutover and proceed with decommission plan.
- `FAIL` keep rollback window open and execute mitigation/rollback as needed.

## Monitoring Table (first 24h)
| Signal | What to watch | Alert threshold | Action |
|---|---|---|---|
| API availability | `GET /api/health` | non-200 or timeout spikes | escalate immediately, verify routing |
| API errors | 4xx/5xx rates | sustained elevated 5xx for 10m | trigger rollback evaluation |
| Auth/session | `/auth/login`, `/me/profile` failures | unexpected 401/403 pattern | verify token flow, comms, decide rollback |
| Core write flow | `/workouts/import` failures | repeated failure bursts | investigate quickly, stop cutover if severe |
| Integrations | Strava/Garmin sync endpoint errors | persistent failures | isolate integration issue, assess rollback impact |

Reference operational docs:
- [docs/operations/php-cutover-monitoring.md](docs/operations/php-cutover-monitoring.md)
- [docs/operations/cutover-roles-and-owners.md](docs/operations/cutover-roles-and-owners.md)
- [docs/operations/node-decommission-plan.md](docs/operations/node-decommission-plan.md)

## Post-cutover
- Observe and log status at `T+5m`, `T+30m`, `T+2h`, `T+24h`.
- Keep Node rollback window open until explicit sign-off.
- After sign-off, execute Node decommission phases.
