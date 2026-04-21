# PHP Cutover Monitoring (First 24h)

## Scope
Operational monitoring requirements immediately after PHP-only traffic switch.

## Metrics to Watch
- API health availability (`/api/health` success/latency).
- API error rates (4xx/5xx).
- Auth/session endpoints (`/api/auth/login`, `/api/me/profile`).
- Core write flow (`/api/workouts/import`).
- Integration endpoints (`/api/integrations/strava/*`, `/api/integrations/garmin/*`).

## Alert Thresholds (minimum)
- Sustained elevated 5xx for 10 minutes -> rollback evaluation.
- Health endpoint instability (timeouts/non-200 spikes) -> immediate escalation.
- Unexpected auth failure pattern (beyond relogin wave) -> investigate token/session flow.
- Repeated import failures -> block further cutover progression.

## Monitoring Cadence
- `T+5m`: critical-path check.
- `T+30m`: core API check.
- `T+2h`: stability trend check.
- `T+24h`: acceptance check.

## Escalation
1. Backend Validation Owner confirms signal.
2. Incident Commander coordinates triage.
3. Rollback Decision Owner decides continue vs rollback.

## Success Criteria at 24h
- No active cutover blockers.
- Error/latency trends stable.
- Auth/session behavior matches expected relogin model.
- Integrations operational.
