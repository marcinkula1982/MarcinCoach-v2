> HISTORYCZNE - nie uzywac jako aktualnej instrukcji.
> Aktywne dokumenty: `docs/status.md` (wykonane funkcjonalnosci), `docs/roadmap.md` (plan), `docs/deploy/frontend-iqhost-deploy.txt` (deploy frontu), `docs/integrations.md` (integracje).
# Node.js -> PHP/Laravel Migration Map

## 1) Training load logic in legacy Node.js

### What exists today (and what does not)
- Legacy Node does **not** implement classic `TSS`, `TRIMP`, `ATL`, `CTL` formulas by name.
- The current model is custom and based on:
  - intensity buckets (`z1Sec..z5Sec`) built from trackpoints,
  - numeric `summary.intensity` used as load contribution,
  - rolling sums over time windows (`weeklyLoad`, `rolling4wLoad`).

### A. Window-level load aggregation
File: `backend/src/training-signals/training-signals.service.ts`

Main methods:
- `getSignalsForUser(userId, opts)`
- `sumIntensity(intensity, durationSec)`

Algorithm:
- Fetches recent workouts from DB (`prisma.workout.findMany`).
- Reads `workout.summary` JSON and derives:
  - `workoutDt` from `summary.startTimeIso` (fallback `createdAt`),
  - `loadValue` from `summary.intensity` only if numeric,
  - intensity buckets from `summary.intensityBuckets` or object `summary.intensity`.
- Computes:
  - `weeklyLoad` = sum of `loadValue` over last 7 days (relative to window end),
  - `rolling4wLoad` = sum of `loadValue` in whole window (default 28 days),
  - total bucket volume via `accumulateIntensity`.

Dependencies:
- `PrismaService` (`workout` table),
- `CLOCK` provider (window fallback when no data),
- schema validation via `trainingSignalsSchema`.

### B. Intensity bucket accumulation helper
File: `backend/src/training-signals/training-signals.utils.ts`

Main methods:
- `emptyIntensity()`
- `accumulateIntensity(a, b)`

Algorithm:
- Safe add of `z1Sec..z5Sec,totalSec` with null guards.
- Used to aggregate per-workout bucket data into period totals.

Dependencies:
- `TrainingSignalsIntensity` type contract.

### C. Per-workout load impact scoring
File: `backend/src/training-feedback-v2/training-feedback-v2-rules.ts`

Main method:
- `calculateLoadImpact(summary)`

Algorithm:
- `weeklyLoadContribution`:
  - numeric `summary.intensity` (or `0`).
- `intensityScore`:
  - weighted sum from bucket object: `z1*1 + z2*2 + z3*3 + z4*4 + z5*5`.

Dependencies:
- `WorkoutSummary` shape (`summary.intensity`),
- consumed by `training-feedback-v2.service.ts` for feedback payload.

### D. Bucket generation from TCX trackpoints
File: `backend/src/workouts/workouts.service.ts`

Main method:
- `computeIntensityBucketsFromTrackpoints(trackpoints)`

Algorithm:
- Builds pace segments (`dt`, `paceSecPerKm`) from ordered trackpoints.
- Filters invalid/noisy segments (`dt <= 0`, `dt > 30`, `dd <= 0`).
- Calculates weighted pace quantiles (`q20`, `q40`, `q60`, `q80`).
- Maps fastest segments to `z5`, slowest to `z1`.

Dependencies:
- TCX parsing and metric pipeline:
  - `parseTcx` (`backend/src/utils/tcxParser.ts`),
  - `computeMetrics` (`backend/src/utils/metrics.ts`),
  - called in `uploadTcxFile()` and then persisted through `importWorkout()`.

### E. Data model dependencies for load logic
- `backend/prisma/schema.prisma` (`Workout` model):
  - `summary` (JSON-as-string),
  - source fields (`source`, `sourceActivityId`, `sourceUserId`),
  - `dedupeKey`.
- `summary` payload fields used by load:
  - `startTimeIso`,
  - `trimmed.durationSec`, `trimmed.distanceM` (fallback `original.*`),
  - `intensity` (numeric or buckets object),
  - optional `intensityBuckets`.

### Migration implications to PHP
- If business target is strict sports science parity:
  - add explicit `TSS/TRIMP` and derived `ATL/CTL` (EWMA).
- If target is legacy parity:
  - port current custom rolling model first, keep formula behavior unchanged.

---

## 2) Garmin/Strava integration inventory (legacy Node.js)

### What exists
Files:
- `backend/src/workouts/dto/import-workout.dto.ts`
- `backend/src/workouts/workouts.controller.ts`
- `backend/src/workouts/workouts.service.ts`
- `backend/prisma/schema.prisma`

Implemented behavior:
- Source enum supports `GARMIN`, `STRAVA`, `MANUAL_UPLOAD`.
- Ingest endpoints:
  - `POST /workouts/upload` (manual TCX),
  - `POST /workouts/import` (generic import contract).
- Dedupe supports source-native activity id:
  - `dedupeKey = SOURCE:sourceActivityId` when available.

### What is missing in Node
- No dedicated OAuth endpoints for Garmin/Strava:
  - no connect URL builder,
  - no callback handler for auth code exchange,
  - no refresh-token rotation flow.
- No dedicated Garmin/Strava API client layer in backend.
- No explicit outbound activity sync jobs from Garmin/Strava API.

### Library usage check
File: `backend/package.json`

Findings:
- No Garmin/Strava SDK or OAuth client dependency.
- No direct indication of dedicated connector package.

Conclusion:
- Node legacy uses source-tagged ingest contract, not full Garmin/Strava OAuth integration.
- It is effectively "import pipeline ready for external sources", but not "full external connector".

---

## 3) Node vs PHP gap analysis (survey/self-report, risk, insights)

## Terminology note
- In this codebase, "medical survey" is represented mainly as workout self-report metadata:
  - `rpe`,
  - `fatigueFlag`,
  - `note`,
  - plus compliance-derived coaching/risk layers.

### Node capabilities (legacy)
Files:
- `backend/src/workouts/workouts.controller.ts`
- `backend/src/training-feedback/training-feedback.controller.ts`
- `backend/src/training-feedback/training-feedback.service.ts`
- `backend/src/training-adjustments/training-adjustments.controller.ts`
- `backend/src/ai-insights/ai-insights.controller.ts`

Behavior:
- `PATCH /workouts/:id/meta` for self-report updates.
- `GET /training-feedback` aggregates window-level:
  - compliance buckets,
  - RPE avg/p50,
  - fatigue true/false counts,
  - recent notes list.
- `GET /training-adjustments` exposes deterministic adjustments.
- `GET /ai/insights` exposes risk/insight layer.

### Current PHP capabilities
Files:
- `backend-php/routes/api.php`
- `backend-php/app/Http/Controllers/Api/WorkoutsController.php`
- `backend-php/app/Services/TrainingSignalsService.php`
- `backend-php/app/Services/TrainingSignalsV2Service.php`
- `backend-php/app/Services/PlanComplianceService.php`
- `backend-php/app/Services/PlanComplianceV2Service.php`

Behavior:
- Workout import/show/signals/compliance endpoints only.
- Per-workout signals/compliance materialization exists (v1/v2).
- No public endpoints for self-report meta aggregation, adjustments, or AI insights.

### Concrete gaps (Node had it, PHP lacks it)
1. **Self-report meta write path missing**
   - Node: `PATCH /workouts/:id/meta`.
   - PHP: no equivalent route/controller method.

2. **Window-level feedback aggregation missing**
   - Node: `GET /training-feedback` with RPE/fatigue/note aggregates.
   - PHP: no equivalent endpoint or service.

3. **Training adjustments layer missing**
   - Node: `GET /training-adjustments`.
   - PHP: no equivalent API/service.

4. **AI insights layer missing**
   - Node: `GET /ai/insights`.
   - PHP: no equivalent API/service.

5. **Load model mismatch risk**
   - Node has user-window aggregates (`weeklyLoad`, `rolling4wLoad`).
   - PHP currently focuses on per-workout signal/compliance rows.

6. **Compliance rule parity not full**
   - PHP has v1/v2 compliance, but Node ecosystem includes additional context-driven layers around feedback/adjustments.

### Business impact of gaps
- Lower personalization quality without self-report loop (`rpe`, `fatigueFlag`, `note`).
- Lower early-risk detection due to missing feedback/insight aggregation.
- Potential UX/API regression for clients expecting Node endpoints.
- Coaching decisions may drift if only per-workout metrics are available.

---

## 4) Migration checklist and priorities

### MVP (parity-first)
1. Add self-report meta endpoint in Laravel:
   - equivalent to Node `PATCH /workouts/:id/meta`.
2. Add feedback aggregation service + endpoint:
   - equivalent to Node `GET /training-feedback`.
3. Add user-window load aggregation:
   - equivalent to `weeklyLoad`/`rolling4wLoad` semantics.
4. Align compliance decision thresholds with Node behavior where required by product.

### Phase 2 (enhancement)
1. Add adjustments endpoint/service parity (`/training-adjustments`).
2. Add insights endpoint/service parity (`/ai/insights`) with rate-limiting strategy.
3. Decide whether to keep custom load model or migrate to explicit `TRIMP/TSS + ATL/CTL`.

### Optional connector track (Garmin/Strava full integration)
1. Add OAuth connect/callback endpoints and token store.
2. Add refresh-token scheduler.
3. Add sync jobs to fetch activities and map into canonical import payload.
4. Keep dedupe contract (`source + sourceActivityId`) as canonical idempotency key.
