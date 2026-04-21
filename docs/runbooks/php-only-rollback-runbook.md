# PHP-only Rollback Runbook

## Purpose
Fast rollback procedure if PHP-only cutover is unhealthy.

## Decision and Ownership
- Go/No-Go Decision Owner: `[TO BE ASSIGNED: Go/No-Go Decision Owner]` (Marcin Kula — Project Owner)
- Rollback Decision Owner: `[TO BE ASSIGNED: Rollback Decision Owner]` (Marcin Kula — Project Owner)
- Rollback Execution Owner: `[TO BE ASSIGNED: Rollback Execution Owner]` (Marcin Kula — Project Owner)
- App Validation Owner: `[TO BE ASSIGNED: App Validation Owner]` (Marcin Kula — Project Owner)
- Communications Owner: `[TO BE ASSIGNED: Communications Owner]` (Marcin Kula — Project Owner)
- Keep assignments identical to [docs/operations/cutover-roles-and-owners.md](docs/operations/cutover-roles-and-owners.md).

- Go/No-Go Decision Owner (reference): see [docs/operations/cutover-roles-and-owners.md](docs/operations/cutover-roles-and-owners.md)
- Rollback Decision Owner: see [docs/operations/cutover-roles-and-owners.md](docs/operations/cutover-roles-and-owners.md)
- Rollback Execution Owner: see [docs/operations/cutover-roles-and-owners.md](docs/operations/cutover-roles-and-owners.md)
- App Validation Owner: see [docs/operations/cutover-roles-and-owners.md](docs/operations/cutover-roles-and-owners.md)
- Communications Owner: see [docs/operations/cutover-roles-and-owners.md](docs/operations/cutover-roles-and-owners.md)

## Rollback Triggers
- Sustained elevated 5xx for 10 minutes.
- Critical auth/session break (`login/me/profile` unusable).
- Smoke phase fails at `T+5m`, `T+30m`, or `T+2h`.
- Critical API path unavailable (`/api/health`, `/api/workouts/import`).

## Steps
1. Declare rollback in incident channel and record timestamp.
2. Switch API traffic back to Node deployment target.
3. Verify Node health endpoint (`/health`) is 200.
4. Run quick verification:
   - login path on Node
   - workout import/list path on Node
   - integrations entrypoints reachable
5. Freeze further cutover changes until incident review.
6. Publish user-facing update (degraded mode / rollback notice).

## Validation After Rollback
- Error rates return near pre-cutover baseline.
- Core login + workout flow works again.
- No traffic remains on PHP target unexpectedly.

## Evidence to Capture
- Trigger that caused rollback.
- Exact rollback decision time.
- Who approved and executed rollback.
- Post-rollback verification results.
