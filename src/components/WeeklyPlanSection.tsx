import { Fragment, useEffect, useState } from 'react'
import { hasStoredSession } from '../api/client'
import { fetchRollingPlan, generateRollingPlan } from '../api/workouts'
import { garminSendWorkout } from '../api/garmin'
import type {
  CrossTrainingIntensity,
  CrossTrainingPromptPreference,
  CrossTrainingSport,
  PlannedCrossTrainingActivity,
  PlannedSession,
  TrainingDay,
  WeeklyPlan,
} from '../types/weekly-plan'

const ADJUSTMENT_LABELS: Record<string, string> = {
  reduce_load: 'Zmniejszono obciążenie (−20%)',
  increase_load: 'Zwiększono obciążenie',
  add_long_run: 'Dodano długie wybieganie',
  reduce_intensity: 'Zmniejszono intensywność',
  increase_intensity: 'Zwiększono intensywność',
  add_rest_day: 'Dodano dzień odpoczynku',
  swap_quality_day: 'Zmieniono dzień akcentu',
  surface_constraint: 'Preferuj teren / unikaj asfaltu',
  shoe_constraint: 'Ograniczenie obuwia',
  cross_training_collision_guard: 'Korekta przez inną aktywność',
}

const RATIONALE_PL: Record<string, string> = {
  'Weekly plan based on last 28 days window':
    'Plan tygodniowy oparty o dane z ostatnich 28 dni',
  'Quality session scheduled based on volume and recovery status':
    'Akcent zaplanowany na podstawie objętości i regeneracji',
  'Long run scheduled on trail due to surface preference':
    'Długie wybieganie zaplanowane w terenie zgodnie z preferencjami nawierzchni',
  'Strides included in easy session (≥3 running days)':
    'Przebieżki dodane do treningu easy (≥3 dni biegania)',
  'Cross-training collision guards applied':
    'Plan skorygowany przez kolizję z inną aktywnością',
}

const SURFACE_PL: Record<string, string> = {
  track: 'bieżnia',
  trail: 'teren',
  road: 'asfalt',
}

const NOTES_PL: Record<string, string> = {
  strides: 'przebieżki',
  hills: 'podbiegi',
  recovery: 'akcent regeneracyjny',
}

const SESSION_TYPE_PL: Record<string, string> = {
  easy: 'easy',
  long: 'long',
  quality: 'akcent',
  threshold: 'próg',
  intervals: 'interwały',
  fartlek: 'fartlek',
  tempo: 'tempo',
  strides: 'przebieżki',
  cross_training: 'inna aktywność',
}

const CROSS_TRAINING_SPORT_PL: Record<CrossTrainingSport | string, string> = {
  bike: 'rower',
  swim: 'pływanie',
  walk_hike: 'marsz/hike',
  strength: 'siłownia',
  other: 'inne',
}

const STRENGTH_SUBTYPE_PL: Record<string, string> = {
  lower_body: 'nogi',
  upper_body: 'góra',
  full_body: 'całe ciało',
  core: 'core',
  mobility: 'mobilność',
}

const INTENSITY_PL: Record<CrossTrainingIntensity | string, string> = {
  easy: 'lekko',
  moderate: 'średnio',
  hard: 'mocno',
}

const HR_ZONE_LABELS: Record<string, string> = {
  Z1: 'Strefa tętna 1 – bardzo lekko (regeneracja)',
  Z2: 'Strefa tętna 2 – lekko (tlen)',
  Z3: 'Strefa tętna 3 – umiarkowanie (tempo)',
  Z4: 'Strefa tętna 4 – mocno (próg)',
  Z5: 'Strefa tętna 5 – bardzo mocno (VO₂max)',
}

const DAY_OFFSETS: Record<TrainingDay, number> = {
  mon: 0,
  tue: 1,
  wed: 2,
  thu: 3,
  fri: 4,
  sat: 5,
  sun: 6,
}

// ---------- Helpers ----------
const dayToPl = (day: TrainingDay): string => {
  const map: Record<TrainingDay, string> = {
    mon: 'pn',
    tue: 'wt',
    wed: 'śr',
    thu: 'czw',
    fri: 'pt',
    sat: 'sob',
    sun: 'nd',
  }
  return map[day]
}

const isoToDate = (iso?: string): string =>
  iso && iso.length >= 10 ? iso.slice(0, 10) : '–'

const resolveSessionDate = (weekStartIso: string, day: TrainingDay): string | null => {
  const offset = DAY_OFFSETS[day]
  if (offset === undefined) return null
  const date = new Date(weekStartIso)
  if (Number.isNaN(date.getTime())) return null
  date.setUTCDate(date.getUTCDate() + offset)
  return date.toISOString().slice(0, 10)
}

const isSendableSession = (session: PlannedSession) =>
  session.type !== 'rest' && session.type !== 'cross_training' && session.durationMin > 0

const sendKey = (session: PlannedSession, index: number) => `${session.day}-${index}`

const garminWorkoutName = (session: PlannedSession, date: string) => {
  const type = SESSION_TYPE_PL[session.type] ?? session.type
  return `MarcinCoach ${type} ${date}`
}

const todayIso = () => new Date().toISOString().slice(0, 10)

const defaultDurationForSport = (sportKind: CrossTrainingSport) => {
  if (sportKind === 'bike') return 30
  if (sportKind === 'strength') return 60
  if (sportKind === 'swim') return 45
  if (sportKind === 'walk_hike') return 60
  return 45
}

const sessionDate = (plan: WeeklyPlan, session: PlannedSession) =>
  session.dateIso ?? resolveSessionDate(plan.weekStartIso, session.day)

const describeCrossTrainingSession = (session: PlannedSession) => {
  if (session.type !== 'cross_training') return null
  const sport = session.sportKind
    ? CROSS_TRAINING_SPORT_PL[session.sportKind] ?? session.sportKind
    : 'inna aktywność'
  const subtype = session.sportSubtype
    ? STRENGTH_SUBTYPE_PL[session.sportSubtype] ?? session.sportSubtype
    : null
  return subtype ? `${sport} · ${subtype}` : sport
}

const hasWorkoutBlocks = (session: PlannedSession) =>
  Array.isArray(session.blocks) && session.blocks.length > 0

// ---------- Component ----------
type WeeklyPlanSectionProps = {
  refreshToken?: number
}

export default function WeeklyPlanSection({ refreshToken = 0 }: WeeklyPlanSectionProps) {
  const [weeklyPlan, setWeeklyPlan] = useState<WeeklyPlan | null>(null)
  const [weeklyPlanLoading, setWeeklyPlanLoading] = useState(false)
  const [weeklyPlanError, setWeeklyPlanError] = useState<string | null>(null)
  const [sendingKey, setSendingKey] = useState<string | null>(null)
  const [sendStatuses, setSendStatuses] = useState<
    Record<string, { type: 'success' | 'error'; message: string }>
  >({})
  const [crossPromptOpen, setCrossPromptOpen] = useState(false)
  const [plannedActivities, setPlannedActivities] = useState<PlannedCrossTrainingActivity[]>([])
  const [skipCrossPrompt, setSkipCrossPrompt] = useState(false)
  const [crossPromptSubmitting, setCrossPromptSubmitting] = useState(false)
  const [expandedDetails, setExpandedDetails] = useState<Record<string, boolean>>({})

  const loadWeeklyPlan = async () => {
    setWeeklyPlanLoading(true)
    setWeeklyPlanError(null)
    try {
      const plan = await fetchRollingPlan(14)
      setWeeklyPlan(plan)
      setSkipCrossPrompt(plan.crossTraining?.promptPreference === 'do_not_ask')
    } catch (e: any) {
      setWeeklyPlan(null)
      const status = e?.response?.status as number | undefined
      const message = e?.response?.data?.message as string | undefined
      
      if (status === 401 || message === 'INVALID_SESSION' || message === 'SESSION_EXPIRED') {
        setWeeklyPlanError('Session invalid – refresh token in browser')
      } else {
        setWeeklyPlanError('Błąd pobierania weekly plan')
      }
    } finally {
      setWeeklyPlanLoading(false)
    }
  }

  useEffect(() => {
    if (hasStoredSession()) {
      loadWeeklyPlan()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [refreshToken])

  const handleSendToGarmin = async (session: PlannedSession, index: number) => {
    if (!weeklyPlan || !isSendableSession(session)) return

    const key = sendKey(session, index)
    const date = sessionDate(weeklyPlan, session)
    if (!date) {
      setSendStatuses((prev) => ({
        ...prev,
        [key]: { type: 'error', message: 'Brak daty' },
      }))
      return
    }

    setSendingKey(key)
    setSendStatuses((prev) => {
      const next = { ...prev }
      delete next[key]
      return next
    })

    try {
      await garminSendWorkout(date, session, garminWorkoutName(session, date))
      setSendStatuses((prev) => ({
        ...prev,
        [key]: { type: 'success', message: 'Wysłano' },
      }))
    } catch (e: any) {
      const message =
        e?.response?.data?.message ||
        e?.response?.data?.error ||
        e?.message ||
        'Błąd wysyłki'
      setSendStatuses((prev) => ({
        ...prev,
        [key]: { type: 'error', message: String(message) },
      }))
    } finally {
      setSendingKey(null)
    }
  }

  const addPlannedActivity = (sportKind: CrossTrainingSport) => {
    setPlannedActivities((prev) => [
      ...prev,
      {
        dateIso: todayIso(),
        sportKind,
        sportSubtype: sportKind === 'strength' ? 'full_body' : null,
        durationMin: defaultDurationForSport(sportKind),
        intensity: sportKind === 'bike' ? 'easy' : 'moderate',
      },
    ])
  }

  const updatePlannedActivity = (
    index: number,
    patch: Partial<PlannedCrossTrainingActivity>,
  ) => {
    setPlannedActivities((prev) =>
      prev.map((activity, i) => (i === index ? { ...activity, ...patch } : activity)),
    )
  }

  const removePlannedActivity = (index: number) => {
    setPlannedActivities((prev) => prev.filter((_, i) => i !== index))
  }

  const submitRollingPlan = async (
    activities: PlannedCrossTrainingActivity[],
    preference?: CrossTrainingPromptPreference,
  ) => {
    setCrossPromptSubmitting(true)
    setWeeklyPlanError(null)
    try {
      const plan = await generateRollingPlan(14, activities, preference)
      setWeeklyPlan(plan)
      setSkipCrossPrompt(plan.crossTraining?.promptPreference === 'do_not_ask')
      setCrossPromptOpen(false)
    } catch (e: any) {
      const message =
        e?.response?.data?.message ||
        e?.message ||
        'Błąd generowania rolling plan'
      setWeeklyPlanError(String(message))
    } finally {
      setCrossPromptSubmitting(false)
    }
  }

  const handleRefreshClick = () => {
    if (weeklyPlan?.crossTraining?.promptPreference === 'do_not_ask') {
      loadWeeklyPlan()
      return
    }
    setCrossPromptOpen(true)
  }

  const submitPromptActivities = () => {
    submitRollingPlan(
      plannedActivities,
      skipCrossPrompt ? 'do_not_ask' : 'ask_before_plan',
    )
  }

  const submitNoOtherActivities = () => {
    submitRollingPlan([], skipCrossPrompt ? 'do_not_ask' : 'ask_before_plan')
  }

  const toggleDetails = (key: string) => {
    setExpandedDetails((prev) => ({ ...prev, [key]: !prev[key] }))
  }

  return (
    <section className="mt-6 rounded-2xl bg-slate-900/60 p-6 shadow-lg ring-1 ring-white/5">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-xl font-semibold text-white">Weekly plan</h2>
        <button
          onClick={handleRefreshClick}
          disabled={weeklyPlanLoading || crossPromptSubmitting}
          className="px-4 py-2 text-sm bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-700 disabled:text-slate-400 rounded-lg text-white transition-colors"
        >
          {weeklyPlanLoading || crossPromptSubmitting ? 'Ładowanie...' : 'Refresh'}
        </button>
      </div>

      {crossPromptOpen && (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/80 px-4">
          <div className="w-full max-w-3xl rounded-2xl border border-slate-700 bg-slate-900 p-5 shadow-2xl">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h3 className="text-lg font-semibold text-white">Inne aktywności w najbliższych 14 dniach</h3>
              </div>
              <button
                type="button"
                onClick={() => setCrossPromptOpen(false)}
                className="rounded-lg px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800"
              >
                Zamknij
              </button>
            </div>

            <div className="mt-4 flex flex-wrap gap-2">
              {(['bike', 'strength', 'swim', 'walk_hike', 'other'] as CrossTrainingSport[]).map((sport) => (
                <button
                  key={sport}
                  type="button"
                  onClick={() => addPlannedActivity(sport)}
                  className="rounded-lg bg-slate-800 px-3 py-2 text-sm font-medium text-slate-100 ring-1 ring-slate-700 hover:bg-slate-700"
                >
                  Dodaj {CROSS_TRAINING_SPORT_PL[sport]}
                </button>
              ))}
            </div>

            <div className="mt-4 max-h-72 space-y-3 overflow-y-auto pr-1">
              {plannedActivities.length === 0 ? (
                <div className="rounded-lg border border-slate-700 bg-slate-950/40 px-4 py-3 text-sm text-slate-400">
                  Brak dodanych aktywności.
                </div>
              ) : (
                plannedActivities.map((activity, index) => (
                  <div
                    key={`${activity.sportKind}-${index}`}
                    className="grid gap-2 rounded-lg border border-slate-700 bg-slate-950/40 p-3 md:grid-cols-[1.1fr_1.2fr_1fr_1fr_auto]"
                  >
                    <input
                      type="date"
                      value={activity.dateIso}
                      onChange={(event) => updatePlannedActivity(index, { dateIso: event.target.value })}
                      className="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white"
                    />
                    <select
                      value={activity.sportKind}
                      onChange={(event) => {
                        const sport = event.target.value as CrossTrainingSport
                        updatePlannedActivity(index, {
                          sportKind: sport,
                          sportSubtype: sport === 'strength' ? activity.sportSubtype ?? 'full_body' : null,
                          durationMin: activity.durationMin || defaultDurationForSport(sport),
                        })
                      }}
                      className="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white"
                    >
                      {(['bike', 'strength', 'swim', 'walk_hike', 'other'] as CrossTrainingSport[]).map((sport) => (
                        <option key={sport} value={sport}>{CROSS_TRAINING_SPORT_PL[sport]}</option>
                      ))}
                    </select>
                    {activity.sportKind === 'strength' ? (
                      <select
                        value={activity.sportSubtype ?? 'full_body'}
                        onChange={(event) => updatePlannedActivity(index, { sportSubtype: event.target.value })}
                        className="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white"
                      >
                        {Object.entries(STRENGTH_SUBTYPE_PL).map(([value, label]) => (
                          <option key={value} value={value}>{label}</option>
                        ))}
                      </select>
                    ) : (
                      <div className="hidden md:block" />
                    )}
                    <div className="grid grid-cols-2 gap-2">
                      <input
                        type="number"
                        min={1}
                        max={360}
                        value={activity.durationMin}
                        onChange={(event) => updatePlannedActivity(index, { durationMin: Number(event.target.value) })}
                        className="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white"
                      />
                      <select
                        value={activity.intensity ?? 'moderate'}
                        onChange={(event) => updatePlannedActivity(index, { intensity: event.target.value as CrossTrainingIntensity })}
                        className="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white"
                      >
                        {(['easy', 'moderate', 'hard'] as CrossTrainingIntensity[]).map((intensity) => (
                          <option key={intensity} value={intensity}>{INTENSITY_PL[intensity]}</option>
                        ))}
                      </select>
                    </div>
                    <button
                      type="button"
                      onClick={() => removePlannedActivity(index)}
                      className="rounded-lg px-3 py-2 text-sm text-slate-300 hover:bg-slate-800"
                    >
                      Usuń
                    </button>
                  </div>
                ))
              )}
            </div>

            <label className="mt-4 flex items-center gap-2 text-sm text-slate-300">
              <input
                type="checkbox"
                checked={skipCrossPrompt}
                onChange={(event) => setSkipCrossPrompt(event.target.checked)}
                className="h-4 w-4 rounded border-slate-600 bg-slate-900"
              />
              Nie pytaj o inne aktywności przed planem
            </label>

            <div className="mt-5 flex flex-wrap justify-end gap-2">
              <button
                type="button"
                onClick={submitNoOtherActivities}
                disabled={crossPromptSubmitting}
                className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-slate-100 hover:bg-slate-700 disabled:opacity-60"
              >
                Brak innych aktywności
              </button>
              <button
                type="button"
                onClick={submitPromptActivities}
                disabled={crossPromptSubmitting}
                className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
              >
                Generuj plan
              </button>
            </div>
          </div>
        </div>
      )}

      {weeklyPlanError && (
        <div className="mb-4 rounded border border-red-500/40 bg-red-900/30 p-4 text-sm text-red-200">
          {weeklyPlanError}
        </div>
      )}

      {weeklyPlanLoading && !weeklyPlan && (
        <div className="text-sm text-slate-400">Ładowanie planu...</div>
      )}

      {weeklyPlan && (
        <div className="space-y-4">
          {/* Summary info */}
          <div className="text-xs text-slate-400 mb-4">
            Okno: {weeklyPlan.windowDays} dni | Start: {isoToDate(weeklyPlan.weekStartIso)} | Koniec: {isoToDate(weeklyPlan.horizonEndIso ?? weeklyPlan.weekEndIso)}
            {weeklyPlan.summary.crossTrainingDurationMin ? (
              <> | Inne: {weeklyPlan.summary.crossTrainingDurationMin} min</>
            ) : null}
          </div>

          {weeklyPlan.appliedAdjustmentsCodes && weeklyPlan.appliedAdjustmentsCodes.length > 0 && (
            <div className="text-xs text-slate-400 mb-4">
              Wprowadzone korekty:{' '}
              {weeklyPlan.appliedAdjustmentsCodes
                .map((c) => ADJUSTMENT_LABELS[c] ?? c)
                .join(', ')}
            </div>
          )}

          {/* Sessions table */}
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-700">
                  <th className="text-left py-2 px-3 text-slate-300">Dzień</th>
                  <th className="text-left py-2 px-3 text-slate-300">Typ</th>
                  <th className="text-left py-2 px-3 text-slate-300">Czas</th>
                  <th className="text-left py-2 px-3 text-slate-300">Intensywność</th>
                  <th className="text-left py-2 px-3 text-slate-300">Powierzchnia</th>
                  <th className="text-left py-2 px-3 text-slate-300">Uwagi</th>
                  <th className="text-left py-2 px-3 text-slate-300">Garmin</th>
                </tr>
              </thead>
              <tbody>
                {weeklyPlan.sessions.map((session, idx) => {
                  const key = sendKey(session, idx)
                  const status = sendStatuses[key]
                  const sendable = isSendableSession(session)
                  const isSending = sendingKey === key
                  const date = sessionDate(weeklyPlan, session)
                  const crossDescription = describeCrossTrainingSession(session)
                  const detailsOpen = expandedDetails[key] === true
                  const canShowDetails = hasWorkoutBlocks(session)

                  return (
                    <Fragment key={key}>
                      <tr className="border-b border-slate-800/50 hover:bg-slate-800/30">
                        <td className="py-2 px-3 text-white font-medium">
                          <div>{dayToPl(session.day)}</div>
                          {date && <div className="text-xs font-normal text-slate-500">{date}</div>}
                        </td>
                        <td className="py-2 px-3 text-slate-300">
                          <div>{SESSION_TYPE_PL[session.type] ?? session.type}</div>
                          {crossDescription && (
                            <div className="text-xs text-slate-500">{crossDescription}</div>
                          )}
                          {canShowDetails && (
                            <button
                              type="button"
                              onClick={() => toggleDetails(key)}
                              className="mt-1 rounded-md bg-slate-800 px-2 py-1 text-xs font-medium text-slate-200 hover:bg-slate-700"
                            >
                              {detailsOpen ? 'Ukryj szczegóły' : 'Szczegóły'}
                            </button>
                          )}
                        </td>
                        <td className="py-2 px-3 text-slate-300">{session.durationMin} min</td>
                        <td className="py-2 px-3 text-slate-300">
                          {session.type === 'cross_training' && session.intensityHint
                            ? INTENSITY_PL[session.intensityHint] ?? session.intensityHint
                            : session.intensityHint
                            ? HR_ZONE_LABELS[session.intensityHint] ?? session.intensityHint
                            : '–'}
                        </td>
                        <td className="py-2 px-3 text-slate-300">
                          {session.surfaceHint
                            ? SURFACE_PL[session.surfaceHint] || session.surfaceHint
                            : '–'}
                        </td>
                        <td className="py-2 px-3 text-slate-300">
                          {session.notes && session.notes.length > 0 ? (
                            <ul className="list-disc list-inside space-y-1">
                              {session.notes.map((note, i) => (
                                <li key={i} className="text-xs">
                                  {note === 'Include 4-6 strides (20-30s each)'
                                    ? 'Dodaj 4–6 przebieżek (po 20–30 s)'
                                    : NOTES_PL[note] ?? note}
                                </li>
                              ))}
                            </ul>
                          ) : (
                            '–'
                          )}
                        </td>
                        <td className="py-2 px-3 text-slate-300">
                          {sendable ? (
                            <div className="space-y-1">
                              <button
                                type="button"
                                onClick={() => handleSendToGarmin(session, idx)}
                                disabled={isSending}
                                className={`min-w-36 rounded-lg px-3 py-1.5 text-xs font-semibold text-white transition ${
                                  isSending
                                    ? 'cursor-not-allowed bg-slate-700 text-slate-300'
                                    : 'bg-emerald-600 hover:bg-emerald-500'
                                }`}
                              >
                                {isSending ? 'Wysyłam...' : 'Wyślij do urządzenia'}
                              </button>
                              {status && (
                                <div
                                  className={`max-w-44 text-xs ${
                                    status.type === 'success' ? 'text-emerald-300' : 'text-amber-300'
                                  }`}
                                >
                                  {status.message}
                                </div>
                              )}
                            </div>
                          ) : (
                            '–'
                          )}
                        </td>
                      </tr>
                      {detailsOpen && canShowDetails && (
                        <tr className="border-b border-slate-800/50 bg-slate-950/30">
                          <td colSpan={7} className="px-3 py-3">
                            <div className="grid gap-3 md:grid-cols-3">
                              {session.blocks?.map((block, blockIndex) => (
                                <div key={`${key}-block-${blockIndex}`} className="border-l border-indigo-400/50 pl-3">
                                  <div className="flex items-baseline justify-between gap-2">
                                    <div className="text-sm font-semibold text-white">{block.title}</div>
                                    <div className="text-xs text-slate-400">{block.durationMin} min</div>
                                  </div>
                                  {block.intensityHint && (
                                    <div className="mt-1 text-xs text-slate-400">
                                      {HR_ZONE_LABELS[block.intensityHint] ?? block.intensityHint}
                                    </div>
                                  )}
                                  {block.description && (
                                    <p className="mt-2 text-xs leading-5 text-slate-300">{block.description}</p>
                                  )}
                                  {block.tips && block.tips.length > 0 && (
                                    <ul className="mt-2 space-y-1 text-xs text-slate-400">
                                      {block.tips.map((tip, tipIndex) => (
                                        <li key={`${key}-tip-${blockIndex}-${tipIndex}`}>{tip}</li>
                                      ))}
                                    </ul>
                                  )}
                                </div>
                              ))}
                            </div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  )
                })}
              </tbody>
            </table>
          </div>

          {/* Rationale */}
          {weeklyPlan.rationale && weeklyPlan.rationale.length > 0 && (
            <div className="mt-4 pt-4 border-t border-slate-700">
              <h3 className="text-sm font-semibold text-slate-300 mb-2">Uzasadnienie:</h3>
              <ul className="list-disc list-inside space-y-1 text-xs text-slate-400">
                {weeklyPlan.rationale.map((point, i) => (
                  <li key={i}>{RATIONALE_PL[point] ?? point}</li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </section>
  )
}

