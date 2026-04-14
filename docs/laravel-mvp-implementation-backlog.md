# Laravel MVP Implementation Backlog (Node parity)

## Goal
Deliver MVP parity with legacy Node in 3 areas:
1) self-report metadata update (`rpe`, `fatigueFlag`, `note`),
2) user-window feedback/load aggregation,
3) key compliance parity rules.

## Workstream 1: Self-report meta endpoint

### 1.1 API route + controller action
- Add route in `backend-php/routes/api.php`:
  - `PATCH /workouts/{id}/meta`
- Add action in `backend-php/app/Http/Controllers/Api/WorkoutsController.php`:
  - validate payload:
    - `workoutMeta.planCompliance` in `planned|modified|unplanned|null`
    - `workoutMeta.rpe` numeric `1..10` or `null`
    - `workoutMeta.fatigueFlag` boolean or `null`
    - `workoutMeta.note` string (trimmed, max length) or `null`
  - ensure workout exists and belongs to user context.

### 1.2 Persistence contract
- Persist `workout_meta` JSON in workouts table with canonical shape:
  - `planCompliance`, `rpe`, `fatigueFlag`, `note`.
- Normalize nullability (same behavior as Node readers).

### 1.3 Tests
- Feature tests:
  - happy path update,
  - invalid `rpe`,
  - missing workout,
  - partial update with null fields.

---

## Workstream 2: Feedback aggregation endpoint (`/training-feedback`)

### 2.1 API route + controller
- Add route in `backend-php/routes/api.php`:
  - `GET /training-feedback?days=28`
- New controller `TrainingFeedbackController` (or action in existing API controller).

### 2.2 Service
- Create `backend-php/app/Services/TrainingFeedbackService.php` with Node-parity logic:
  - fetch workouts for user (ordered desc, bounded limit),
  - derive `workoutDt` from `summary.startTimeIso` fallback created time,
  - filter by window ending at latest workout date (deterministic),
  - aggregate:
    - counts: `planned|modified|unplanned|unknown`,
    - compliance rates `%`,
    - RPE: `samples`, `avg`, `p50`,
    - fatigue: `trueCount`, `falseCount`,
    - notes: `samples`, `last5` (latest first, trimmed non-empty).

### 2.3 Response contract (stable)
- Return payload matching Node semantics:
  - `generatedAtIso`, `windowDays`, `counts`, `complianceRate`, `rpe`, `fatigue`, `notes`.

### 2.4 Tests
- Unit tests for aggregation math (including edge cases).
- Feature tests for endpoint and `days` validation.

---

## Workstream 3: User-window load aggregation parity

### 3.1 API route + controller
- Add route:
  - `GET /training-signals?days=28`
- Add `TrainingSignalsController` + service method `getSignalsForUser`.

### 3.2 Service logic (Node parity)
- Create/extend `TrainingSignalsService` with user-window aggregation:
  - parse summary safely,
  - `loadValue` from numeric `summary.intensity`,
  - optional bucket object from `summary.intensityBuckets` or object `summary.intensity`,
  - compute:
    - `weeklyLoad` (last 7 days from window end),
    - `rolling4wLoad` (window total),
    - volume + intensity totals + long run + consistency.

### 3.3 Tests
- deterministic date-window behavior,
- mixed data (`summary.intensity` number vs object),
- empty dataset behavior.

---

## Workstream 4: Compliance parity upgrades (minimum set)

### 4.1 Gap closure in compliance rules
- Audit Node `plan-compliance.evaluate` vs:
  - `PlanComplianceService.php`
  - `PlanComplianceV2Service.php`
- Implement missing high-impact checks first:
  - rest-day violation equivalent,
  - skipped session handling,
  - key threshold alignment for status buckets.

### 4.2 Tests
- Fixture-based rule parity tests against representative Node-like scenarios.

---

## Suggested delivery order (sprint-safe)
1. `PATCH /workouts/{id}/meta` (smallest dependency surface),
2. `GET /training-feedback`,
3. `GET /training-signals` (user-window),
4. compliance parity deltas.

## Definition of done (MVP)
- All 3 new/extended endpoints available and covered by tests.
- Aggregation outputs are deterministic and match Node semantics on shared fixtures.
- No breaking changes to existing `/workouts/import`, `/workouts/{id}/signals`, `/compliance`, `/compliance-v2`.
