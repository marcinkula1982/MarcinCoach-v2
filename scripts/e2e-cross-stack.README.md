# e2e-cross-stack.mjs

Runs the same fixture dataset through the Node and PHP backends and diffs their responses on whitelisted fields.

## When to run

- Before merging changes that touch signals/adjustments logic on either side.
- After a port/refactor, to confirm the two backends agree on shared semantics.

## Prerequisites

1. Node backend running (default `http://localhost:3000`).
2. PHP backend running (default `http://localhost:8000/api`).
3. Both backends pointed at scratch databases — the script creates synthetic workouts.

## Usage

```bash
node scripts/e2e-cross-stack.mjs
# or override
NODE_BASE=http://localhost:3000 \
PHP_BASE=http://localhost:8000/api \
DAYS=28 \
  node scripts/e2e-cross-stack.mjs
```

Exit codes:
- `0` — compared fields match across both backends.
- `1` — divergence detected (diff is printed).
- `2` — setup error (backend unreachable, import failed, etc.).

## What is compared

Only a whitelisted subset — see `COMPARE_FIELDS_SIGNALS` and `COMPARE_FIELDS_ADJUSTMENTS` in the script. Add fields as the contracts converge.

## Extending

- Replace the synthetic `fixtures` array with real TCX payloads for deeper coverage.
- Add more endpoints by duplicating the `get(...)` / `pick(...)` / `printDiff(...)` block.

## Parity expansion plan (Node -> PHP)

Current script validates only:
- `GET /training-signals`
- `GET /training-adjustments`

Target scope for migration parity should include the following endpoint groups.

| Group | Node endpoint(s) | PHP endpoint(s) | Compare fields (minimum) |
|---|---|---|---|
| AI insights | `GET /ai/insights` | `GET /api/ai/insights` | `payload.generatedAtIso`, `payload.windowDays`, `payload.risks`, `cache` |
| AI plan | `GET /ai/plan` | `GET /api/ai/plan`, `POST /api/ai/plan` | `windowDays`, `plan.sessions`, `adjustments.adjustments`, `explanation.summaryPl` |
| Feedback v2 | `POST /training-feedback-v2/:id/generate`, `GET /training-feedback-v2/signals/:id`, `POST /training-feedback-v2/:id/question` | matching `/api/training-feedback-v2/*` | `character`, `coachSignals`, mapped `signals`, question `answer` shape |
| Workouts core | `GET /workouts`, `GET /workouts/:id`, `POST /workouts/import` | matching `/api/workouts/*` | list item identity fields, `summary` shape, show payload shape |
| Profile | `GET /me/profile`, `PUT /me/profile` | matching `/api/me/profile` (to be implemented) | full profile JSON parity |
| Auth | `POST /auth/login` | matching `/api/auth/login` (to be implemented) | `sessionToken`, `username` |

### Suggested rollout

1. Add AI insights and AI plan comparisons first (already available in both stacks).
2. Add feedback-v2 comparisons next (generate/signals/question happy path).
3. Add workouts list/show/import comparisons once contracts are aligned.
4. Add profile/auth comparisons after PHP endpoints are implemented.

### Gating policy

- Pull requests that touch shared Node/PHP logic should run this script in CI.
- Start with warnings-only for new endpoint groups.
- Switch to fail-on-diff after contracts stabilize for that group.
