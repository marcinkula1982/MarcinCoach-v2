import { useEffect, useRef, useState } from 'react'
import { fetchAiPlan } from '../api/ai-plan'
import type { AiPlanResponse } from '../types/ai-plan'

const dayToPl = (day: string): string => {
  const map: Record<string, string> = {
    mon: 'pn',
    tue: 'wt',
    wed: 'śr',
    thu: 'czw',
    fri: 'pt',
    sat: 'sob',
    sun: 'nd',
  }
  return map[day] ?? day
}

export default function AiPlanSection() {
  const [data, setData] = useState<AiPlanResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const inFlight = useRef<AbortController | null>(null)

  const load = async () => {
    // cancel any previous request (important for React.StrictMode double-mount in dev)
    inFlight.current?.abort()
    const controller = new AbortController()
    inFlight.current = controller

    setLoading(true)
    setError(null)
    try {
      const data = await fetchAiPlan(28, { signal: controller.signal })
      setData(data)
      console.log('AI PLAN RAW RESPONSE', data)
    } catch (err: any) {
      if (err?.code === 'ERR_CANCELED') return
      console.error('AI PLAN FETCH ERROR', err)
      setData(null)
      const status = err?.response?.status as number | undefined
      const message = err?.response?.data?.message as string | undefined

      if (status === 401 || message === 'INVALID_SESSION' || message === 'SESSION_EXPIRED') {
        setError('Session invalid – refresh token in browser')
      } else {
        setError('Błąd pobierania AI plan')
      }
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const sessionToken = localStorage.getItem('tcx-session-token')
    if (sessionToken) {
      load()
    }
    return () => {
      inFlight.current?.abort()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <section className="mt-6 rounded-2xl bg-slate-900/60 p-6 shadow-lg ring-1 ring-white/5">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-xl font-semibold text-white">AI plan</h2>
        <button
          onClick={load}
          disabled={loading}
          className="px-4 py-2 text-sm bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-700 disabled:text-slate-400 rounded-lg text-white transition-colors"
        >
          {loading ? 'Ładowanie...' : 'Refresh'}
        </button>
      </div>

      {error && (
        <div className="mb-4 rounded border border-red-500/40 bg-red-900/30 p-4 text-sm text-red-200">
          {error}
        </div>
      )}

      {loading && !data && <div className="text-sm text-slate-400">Ładowanie...</div>}

      {data && (
        <div className="space-y-4">
          <div>
            <div className="text-sm font-semibold text-white">{data.explanation.titlePl}</div>
            {data.provider && (
              <div className="mt-1 text-xs text-slate-400">Provider: {data.provider}</div>
            )}
          </div>

          {data.explanation.summaryPl?.length > 0 && (
            <ul className="list-disc list-inside space-y-1 text-sm text-slate-300">
              {data.explanation.summaryPl.map((p, i) => (
                <li key={i}>{p}</li>
              ))}
            </ul>
          )}

          {data.explanation.warningsPl?.length > 0 && (
            <div className="rounded border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-200">
              <div className="font-semibold mb-2">Ostrzeżenia</div>
              <ul className="list-disc list-inside space-y-1">
                {data.explanation.warningsPl.map((w, i) => (
                  <li key={i}>{w}</li>
                ))}
              </ul>
            </div>
          )}

          {data.explanation.sessionNotesPl?.length > 0 && (
            <div className="rounded border border-slate-700/60 bg-slate-900/40 p-4 text-sm text-slate-200">
              <div className="font-semibold mb-2">Uwagi do sesji</div>
              <ul className="space-y-1">
                {data.explanation.sessionNotesPl.map((n, i) => (
                  <li key={i}>
                    <span className="font-semibold">{dayToPl(n.day)}:</span> {n.text}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </section>
  )
}


