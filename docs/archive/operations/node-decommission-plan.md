> HISTORYCZNE - nie uzywac jako aktualnej instrukcji.
> Aktywne dokumenty: `docs/status.md` (wykonane funkcjonalnosci), `docs/roadmap.md` (plan), `docs/deploy/frontend-iqhost-deploy.txt` (deploy frontu), `docs/integrations.md` (integracje).
# Node Decommission Plan (Post-Cutover)

## Purpose
Controlled shutdown of Node runtime after PHP-only cutover stabilizes.

## Preconditions
- `T+24h` cutover acceptance is PASS.
- Rollback Decision Owner approves decommission start.
- No unresolved production blockers linked to PHP backend.

## Phase 1: Remove from Live Traffic
- Ensure all API traffic routes to PHP target only.
- Keep Node deployment available during rollback window.

## Phase 2: Close Rollback Window
- Confirm no rollback triggers remain active.
- Record explicit decision to close rollback window.

## Phase 3: Disable Node Operational Artifacts
- Disable Node deployment target.
- Disable or archive Node-specific parity/deploy gates when no longer required.

## Phase 4: Remove Node Runtime Assets
- Remove Node backend CI/deploy artifacts that are no longer used.
- Remove `backend/` NestJS runtime only after sign-off that there is no runtime dependency.

## Verification
- No production requests routed to Node.
- PHP health and core APIs remain stable after each phase.
- Decision log updated with timestamps and owners.
