import { useEffect, useState } from 'react'
import { fetchWeeklyPlan } from '../api/workouts'
import type { WeeklyPlan, TrainingDay } from '../types/weekly-plan'

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

// ---------- Component ----------
export default function WeeklyPlanSection() {
  const [weeklyPlan, setWeeklyPlan] = useState<WeeklyPlan | null>(null)
  const [weeklyPlanLoading, setWeeklyPlanLoading] = useState(false)
  const [weeklyPlanError, setWeeklyPlanError] = useState<string | null>(null)

  const loadWeeklyPlan = async () => {
    setWeeklyPlanLoading(true)
    setWeeklyPlanError(null)
    try {
      const plan = await fetchWeeklyPlan(28)
      setWeeklyPlan(plan)
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
    const sessionToken = localStorage.getItem('tcx-session-token')
    const loggedInUser = localStorage.getItem('tcx-username')
    
    if (loggedInUser && sessionToken) {
      loadWeeklyPlan()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <section className="mt-6 rounded-2xl bg-slate-900/60 p-6 shadow-lg ring-1 ring-white/5">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-xl font-semibold text-white">Weekly plan</h2>
        <button
          onClick={loadWeeklyPlan}
          disabled={weeklyPlanLoading}
          className="px-4 py-2 text-sm bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-700 disabled:text-slate-400 rounded-lg text-white transition-colors"
        >
          {weeklyPlanLoading ? 'Ładowanie...' : 'Refresh'}
        </button>
      </div>

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
            Okno: {weeklyPlan.windowDays} dni | Tydzień: {isoToDate(weeklyPlan.weekStartIso)} – {isoToDate(weeklyPlan.weekEndIso)}
          </div>

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
                </tr>
              </thead>
              <tbody>
                {weeklyPlan.sessions.map((session, idx) => (
                  <tr key={`${session.day}-${idx}`} className="border-b border-slate-800/50 hover:bg-slate-800/30">
                    <td className="py-2 px-3 text-white font-medium">{dayToPl(session.day)}</td>
                    <td className="py-2 px-3 text-slate-300">{session.type}</td>
                    <td className="py-2 px-3 text-slate-300">{session.durationMin} min</td>
                    <td className="py-2 px-3 text-slate-300">{session.intensityHint || '–'}</td>
                    <td className="py-2 px-3 text-slate-300">{session.surfaceHint || '–'}</td>
                    <td className="py-2 px-3 text-slate-300">
                      {session.notes && session.notes.length > 0 ? (
                        <ul className="list-disc list-inside space-y-1">
                          {session.notes.map((note, i) => (
                            <li key={i} className="text-xs">{note}</li>
                          ))}
                        </ul>
                      ) : (
                        '–'
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Rationale */}
          {weeklyPlan.rationale && weeklyPlan.rationale.length > 0 && (
            <div className="mt-4 pt-4 border-t border-slate-700">
              <h3 className="text-sm font-semibold text-slate-300 mb-2">Uzasadnienie:</h3>
              <ul className="list-disc list-inside space-y-1 text-xs text-slate-400">
                {weeklyPlan.rationale.map((point, i) => (
                  <li key={i}>{point}</li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </section>
  )
}

