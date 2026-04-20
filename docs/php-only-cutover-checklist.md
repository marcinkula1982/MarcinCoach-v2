# PHP-only Cutover Checklist

## Goal
- Move runtime to 100% PHP backend.
- Keep Node runtime only for frontend/tooling where explicitly needed.

## Pre-cutover
- Verify FE points to PHP API in all environments.
- Confirm auth/profile/workouts/ai/training-feedback-v2 flows work against PHP.
- Confirm Strava OAuth flow returns valid tokens.
- Confirm Garmin connector sync returns normalized activities.
- Confirm `php artisan test` is green.

## Cutover
- Route all API traffic to PHP backend.
- Disable Node backend deployment target.
- Run smoke tests:
  - `/auth/login`
  - `/me/profile`
  - `/workouts/import`, `/workouts`, `/workouts/{id}`
  - `/training-signals`, `/training-adjustments`
  - `/ai/insights`, `/ai/plan`
  - `/integrations/strava/*`, `/integrations/garmin/*`

## Post-cutover
- Observe 4xx/5xx rates for 24h.
- Verify background sync runs for Strava/Garmin.
- Remove Node backend CI jobs and deployment manifests.
- Remove `backend/` NestJS backend when no runtime dependency remains.
