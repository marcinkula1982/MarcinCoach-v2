#!/usr/bin/env node
/**
 * E2E cross-stack fixture test.
 *
 * Runs a small fixture dataset through the Node backend AND the PHP/Laravel backend,
 * then diffs the responses for a shared endpoint (default: /training-signals?days=28).
 * Purpose: catch silent divergence between the two backends.
 *
 * Prerequisites:
 *   - Both backends running locally.
 *   - Default URLs: NODE_BASE=http://localhost:3000, PHP_BASE=http://localhost:8000/api
 *
 * Usage:
 *   node scripts/e2e-cross-stack.mjs
 *   NODE_BASE=http://localhost:3000 PHP_BASE=http://localhost:8000/api \
 *     node scripts/e2e-cross-stack.mjs
 *
 * Exit code:
 *   0 — responses match on the compared fields
 *   1 — divergence detected (details printed to stdout)
 *   2 — setup error (backend unreachable, import failed, etc.)
 */

const NODE_BASE = process.env.NODE_BASE ?? 'http://localhost:3000'
const PHP_BASE = process.env.PHP_BASE ?? 'http://localhost:8000/api'
const DAYS = Number(process.env.DAYS ?? 28)
const USERNAME = process.env.E2E_USERNAME ?? 'e2e-user'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-password'
const PHP_ONLY = String(process.env.PHP_ONLY ?? '').toLowerCase() === '1'

// Minimal deterministic fixture — three workouts across the window.
const fixtures = [
  {
    source: 'manual',
    sourceActivityId: 'e2e-fix-1',
    startTimeIso: '2026-04-01T06:30:00Z',
    durationSec: 3600,
    distanceM: 12000,
  },
  {
    source: 'manual',
    sourceActivityId: 'e2e-fix-2',
    startTimeIso: '2026-04-08T06:30:00Z',
    durationSec: 2700,
    distanceM: 8000,
  },
  {
    source: 'manual',
    sourceActivityId: 'e2e-fix-3',
    startTimeIso: '2026-04-15T06:30:00Z',
    durationSec: 5400,
    distanceM: 18000, // long run
  },
]

/**
 * Fields where PHP and Node contracts are known to overlap exactly.
 * Anything outside this whitelist is ignored in the diff so the test
 * does not flag known schema deltas as errors.
 */
const COMPARE_FIELDS_SIGNALS = [
  'windowDays',
  'weeklyLoad',
  'rolling4wLoad',
  'longRun.exists',
]

const COMPARE_FIELDS_ADJUSTMENTS = ['windowDays', 'adjustments']
const COMPARE_FIELDS_AI_INSIGHTS = ['payload.windowDays', 'payload.risks', 'cache']
const COMPARE_FIELDS_AI_PLAN = ['windowDays', 'plan.sessions', 'adjustments.adjustments']
const COMPARE_FIELDS_WORKOUT_SHOW = ['summary.startTimeIso', 'summary.durationSec', 'summary.distanceM']
const COMPARE_FIELDS_PROFILE = ['preferredRunDays', 'preferredSurface', 'goals', 'constraints']
const COMPARE_FIELDS_FEEDBACK_V2 = ['character', 'coachSignals', 'metrics']

function getByPath(obj, path) {
  return path.split('.').reduce((acc, k) => (acc == null ? undefined : acc[k]), obj)
}

function pick(obj, paths) {
  const out = {}
  for (const p of paths) {
    out[p] = getByPath(obj, p)
  }
  return out
}

async function post(base, path, body, headers = {}) {
  const res = await fetch(`${base}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...headers },
    body: JSON.stringify(body),
  })
  const text = await res.text()
  if (!res.ok) {
    throw new Error(`POST ${base}${path} failed: ${res.status} ${text}`)
  }
  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}

async function put(base, path, body, headers = {}) {
  const res = await fetch(`${base}${path}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', ...headers },
    body: JSON.stringify(body),
  })
  const text = await res.text()
  if (!res.ok) {
    throw new Error(`PUT ${base}${path} failed: ${res.status} ${text}`)
  }
  return JSON.parse(text)
}

async function get(base, path, headers = {}) {
  const res = await fetch(`${base}${path}`, { headers })
  const text = await res.text()
  if (!res.ok) {
    throw new Error(`GET ${base}${path} failed: ${res.status} ${text}`)
  }
  return JSON.parse(text)
}

function printDiff(label, nodeVal, phpVal) {
  console.log(`\n== ${label} ==`)
  console.log('Node:', JSON.stringify(nodeVal))
  console.log('PHP :', JSON.stringify(phpVal))
  console.log('MATCH:', JSON.stringify(nodeVal) === JSON.stringify(phpVal))
}

async function main() {
  console.log(`NODE_BASE=${NODE_BASE}`)
  console.log(`PHP_BASE =${PHP_BASE}`)
  console.log(`DAYS     =${DAYS}\n`)

  // 1) Create sessions (both stacks or PHP-only).
  let nodeLogin, phpLogin
  try {
    if (!PHP_ONLY) {
      await post(NODE_BASE, '/auth/register', { username: USERNAME, password: PASSWORD }).catch(() => null)
    }
    await post(PHP_BASE, '/auth/register', { username: USERNAME, password: PASSWORD }).catch(() => null)
    if (PHP_ONLY) {
      phpLogin = await post(PHP_BASE, '/auth/login', { username: USERNAME, password: PASSWORD })
    } else {
      ;[nodeLogin, phpLogin] = await Promise.all([
        post(NODE_BASE, '/auth/login', { username: USERNAME, password: PASSWORD }),
        post(PHP_BASE, '/auth/login', { username: USERNAME, password: PASSWORD }),
      ])
    }
  } catch (err) {
    console.error(`[auth setup error] ${err.message}`)
    process.exit(2)
  }

  const nodeHeaders = !PHP_ONLY ? {
    'x-username': USERNAME,
    'x-session-token': nodeLogin.sessionToken,
  } : {}
  const phpHeaders = {
    'x-username': USERNAME,
    'x-session-token': phpLogin.sessionToken,
  }

  // 2) Import same fixtures into both backends.
  for (const fx of fixtures) {
    try {
      if (!PHP_ONLY) {
        await post(NODE_BASE, '/workouts/import', fx, nodeHeaders)
      }
      await post(PHP_BASE, '/workouts/import', fx, phpHeaders)
      console.log(`[ok] imported ${fx.sourceActivityId}`)
    } catch (err) {
      console.error(`[setup error] ${err.message}`)
      process.exit(2)
    }
  }

  // 3) Fetch the comparison endpoints.
  let nodeSignals, phpSignals, nodeAdj, phpAdj
  try {
    if (PHP_ONLY) {
      phpSignals = await get(PHP_BASE, `/training-signals?days=${DAYS}`, phpHeaders)
    } else {
      ;[nodeSignals, phpSignals] = await Promise.all([
        get(NODE_BASE, `/training-signals?days=${DAYS}`, nodeHeaders),
        get(PHP_BASE, `/training-signals?days=${DAYS}`, phpHeaders),
      ])
    }
  } catch (err) {
    console.error(`[fetch error /training-signals] ${err.message}`)
    process.exit(2)
  }

  try {
    if (PHP_ONLY) {
      phpAdj = await get(PHP_BASE, `/training-adjustments?days=${DAYS}`, phpHeaders)
    } else {
      ;[nodeAdj, phpAdj] = await Promise.all([
        get(NODE_BASE, `/training-adjustments?days=${DAYS}`, nodeHeaders),
        get(PHP_BASE, `/training-adjustments?days=${DAYS}`, phpHeaders),
      ])
    }
  } catch (err) {
    console.error(`[fetch error /training-adjustments] ${err.message}`)
    process.exit(2)
  }

  // 4) Compare on whitelisted fields only.
  const sigNode = !PHP_ONLY ? pick(nodeSignals, COMPARE_FIELDS_SIGNALS) : null
  const sigPhp = pick(phpSignals, COMPARE_FIELDS_SIGNALS)

  const adjNode = !PHP_ONLY ? pick(nodeAdj, COMPARE_FIELDS_ADJUSTMENTS) : null
  const adjPhp = pick(phpAdj, COMPARE_FIELDS_ADJUSTMENTS)

  if (!PHP_ONLY) {
    printDiff('/training-signals (whitelisted)', sigNode, sigPhp)
    printDiff('/training-adjustments (whitelisted)', adjNode, adjPhp)
  } else {
    console.log('\nPHP-only mode: signals/adjustments fetched successfully.')
  }

  // 5) Additional parity checks for migrated AI + workouts contracts.
  let nodeInsights = null
  let phpInsights = null
  let nodePlan = null
  let phpPlan = null
  let nodeWorkouts = null
  let phpWorkouts = null
  let nodeWorkoutShow = null
  let phpWorkoutShow = null
  let nodeProfilePick = null
  let phpProfilePick = null
  let nodeFeedbackPick = null
  let phpFeedbackPick = null
  try {
    if (PHP_ONLY) {
      phpInsights = await get(PHP_BASE, `/ai/insights?days=${DAYS}`, phpHeaders)
      phpPlan = await get(PHP_BASE, `/ai/plan?days=${DAYS}`, phpHeaders)
      phpWorkouts = await get(PHP_BASE, '/workouts', phpHeaders)
    } else {
      ;[nodeInsights, phpInsights] = await Promise.all([
        get(NODE_BASE, `/ai/insights?days=${DAYS}`, nodeHeaders),
        get(PHP_BASE, `/ai/insights?days=${DAYS}`, phpHeaders),
      ])
      ;[nodePlan, phpPlan] = await Promise.all([
        get(NODE_BASE, `/ai/plan?days=${DAYS}`, nodeHeaders),
        get(PHP_BASE, `/ai/plan?days=${DAYS}`, phpHeaders),
      ])
      ;[nodeWorkouts, phpWorkouts] = await Promise.all([
        get(NODE_BASE, '/workouts', nodeHeaders),
        get(PHP_BASE, '/workouts', phpHeaders),
      ])
    }
    if (!PHP_ONLY && Array.isArray(nodeWorkouts) && Array.isArray(phpWorkouts) && nodeWorkouts[0] && phpWorkouts[0]) {
      const nodeId = nodeWorkouts[0].id
      const phpId = phpWorkouts[0].id
      ;[nodeWorkoutShow, phpWorkoutShow] = await Promise.all([
        get(NODE_BASE, `/workouts/${nodeId}`, nodeHeaders),
        get(PHP_BASE, `/workouts/${phpId}`, phpHeaders),
      ])
    }

    const profilePatch = {
      preferredRunDays: 'Mon,Wed,Fri',
      preferredSurface: 'mixed',
      goals: 'Consistency',
      constraints: 'None',
    }
    if (PHP_ONLY) {
      await put(PHP_BASE, '/me/profile', profilePatch, phpHeaders)
      const phpProfile = await get(PHP_BASE, '/me/profile', phpHeaders)
      phpProfilePick = pick(phpProfile, COMPARE_FIELDS_PROFILE)
      console.log('\nPHP-only mode: /me/profile updated and fetched.')
    } else {
      await Promise.all([
        put(NODE_BASE, '/me/profile', profilePatch, nodeHeaders),
        put(PHP_BASE, '/me/profile', profilePatch, phpHeaders),
      ])
      const [nodeProfile, phpProfile] = await Promise.all([
        get(NODE_BASE, '/me/profile', nodeHeaders),
        get(PHP_BASE, '/me/profile', phpHeaders),
      ])
      nodeProfilePick = pick(nodeProfile, COMPARE_FIELDS_PROFILE)
      phpProfilePick = pick(phpProfile, COMPARE_FIELDS_PROFILE)
      printDiff('/me/profile (whitelisted)', nodeProfilePick, phpProfilePick)
    }
    if (!PHP_ONLY && Array.isArray(nodeWorkouts) && Array.isArray(phpWorkouts) && nodeWorkouts[0] && phpWorkouts[0]) {
      const nodeWorkoutId = nodeWorkouts[0].id
      const phpWorkoutId = phpWorkouts[0].id
      const [nodeFeedback, phpFeedback] = await Promise.all([
        post(NODE_BASE, `/training-feedback-v2/${nodeWorkoutId}/generate`, {}, nodeHeaders),
        post(PHP_BASE, `/training-feedback-v2/${phpWorkoutId}/generate`, {}, phpHeaders),
      ])
      nodeFeedbackPick = pick(nodeFeedback, COMPARE_FIELDS_FEEDBACK_V2)
      phpFeedbackPick = pick(phpFeedback, COMPARE_FIELDS_FEEDBACK_V2)
      printDiff('/training-feedback-v2/generate (whitelisted)', nodeFeedbackPick, phpFeedbackPick)
    }
  } catch (err) {
    console.error(`[fetch error additional parity] ${err.message}`)
    process.exit(2)
  }

  const aiInsightsNode = !PHP_ONLY ? pick(nodeInsights, COMPARE_FIELDS_AI_INSIGHTS) : null
  const aiInsightsPhp = pick(phpInsights, COMPARE_FIELDS_AI_INSIGHTS)
  const aiPlanNode = !PHP_ONLY ? pick(nodePlan, COMPARE_FIELDS_AI_PLAN) : null
  const aiPlanPhp = pick(phpPlan, COMPARE_FIELDS_AI_PLAN)
  const workoutsCountNode = !PHP_ONLY && Array.isArray(nodeWorkouts) ? nodeWorkouts.length : -1
  const workoutsCountPhp = Array.isArray(phpWorkouts) ? phpWorkouts.length : -1
  const workoutShowNode = !PHP_ONLY && nodeWorkoutShow ? pick(nodeWorkoutShow, COMPARE_FIELDS_WORKOUT_SHOW) : null
  const workoutShowPhp = phpWorkoutShow ? pick(phpWorkoutShow, COMPARE_FIELDS_WORKOUT_SHOW) : null

  if (!PHP_ONLY) {
    printDiff('/ai/insights (whitelisted)', aiInsightsNode, aiInsightsPhp)
    printDiff('/ai/plan (whitelisted)', aiPlanNode, aiPlanPhp)
    printDiff('/workouts count', workoutsCountNode, workoutsCountPhp)
    printDiff('/workouts/:id (whitelisted)', workoutShowNode, workoutShowPhp)
  } else {
    console.log('\nPHP-only mode: AI/workouts endpoints fetched successfully.')
  }

  const match =
    (PHP_ONLY || JSON.stringify(sigNode) === JSON.stringify(sigPhp)) &&
    (PHP_ONLY || JSON.stringify(adjNode) === JSON.stringify(adjPhp)) &&
    (PHP_ONLY || JSON.stringify(aiInsightsNode) === JSON.stringify(aiInsightsPhp)) &&
    (PHP_ONLY || JSON.stringify(aiPlanNode) === JSON.stringify(aiPlanPhp)) &&
    (PHP_ONLY || JSON.stringify(workoutsCountNode) === JSON.stringify(workoutsCountPhp)) &&
    (PHP_ONLY || JSON.stringify(workoutShowNode) === JSON.stringify(workoutShowPhp)) &&
    (PHP_ONLY || JSON.stringify(nodeProfilePick) === JSON.stringify(phpProfilePick)) &&
    (PHP_ONLY || JSON.stringify(nodeFeedbackPick) === JSON.stringify(phpFeedbackPick))

  if (!match) {
    console.error('\nDIVERGENCE DETECTED — see diffs above.')
    process.exit(1)
  }

  console.log('\nAll compared fields match across Node and PHP.')
  process.exit(0)
}

main().catch((err) => {
  console.error(err)
  process.exit(2)
})
