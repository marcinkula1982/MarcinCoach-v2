import { useEffect, useState } from 'react'
import client from '../api/client'

type PlanComplianceCounts = {
  planned: number
  modified: number
  unplanned: number
}

type Totals = {
  workouts: number
  distanceKm: number
  durationMin: number
  planCompliance: PlanComplianceCounts
  fatigueFlags: number
}

type ByWeekRow = {
  week: string
  workouts: number
  distanceKm: number
  durationMin: number
}

type ByDayRow = {
  day: string // YYYY-MM-DD
  workouts: number
  distanceKm: number
  durationMin: number
}

type SummaryResponse = {
  totals: Totals
  byWeek: ByWeekRow[]
  byDay?: ByDayRow[]
}

export default function AnalyticsSummary() {
  const [data, setData] = useState<SummaryResponse | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let alive = true
    setLoading(true)
    setErr(null)

    client
      .get<SummaryResponse>('/workouts/analytics/summary')
      .then((res) => {
        if (!alive) return
        setData(res.data)
      })
      .catch((e) => {
        if (!alive) return
        const msg =
          e?.response?.data?.message ||
          e?.response?.data ||
          e?.message ||
          String(e)
        setErr(String(msg))
      })
      .finally(() => {
        if (!alive) return
        setLoading(false)
      })

    return () => {
      alive = false
    }
  }, [])

  if (loading) {
    return (
      <div className="mt-8 rounded-xl border border-slate-800 bg-slate-900/40 p-4 text-sm text-slate-300">
        Ładowanie analytics…
      </div>
    )
  }

  if (err) {
    return (
      <div className="mt-8 rounded-xl border border-red-500/40 bg-red-900/30 p-4 text-sm text-red-100">
        Analytics error: {err}
      </div>
    )
  }

  if (!data) return null

  const t = data.totals

  return (
    <section className="mt-8 rounded-2xl bg-slate-900/60 p-6 shadow-lg ring-1 ring-white/5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-xs uppercase tracking-[0.2em] text-indigo-300/80">
            Analytics
          </p>
          <h2 className="text-xl font-semibold text-white">Podsumowanie</h2>
          <p className="mt-1 text-sm text-slate-300">
            Read-only z backendu: <span className="font-mono">/workouts/analytics/summary</span>
          </p>
        </div>
      </div>

      {/* Totals */}
      <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card label="Treningi" value={t.workouts} />
        <Card label="Dystans" value={`${t.distanceKm.toFixed(2)} km`} />
        <Card label="Czas" value={`${t.durationMin.toFixed(2)} min`} />
        <Card label="Fatigue flags" value={t.fatigueFlags} />
      </div>

      {/* Compliance */}
      <div className="mt-6 rounded-xl border border-slate-800 bg-slate-950/30 p-4">
        <div className="text-sm text-slate-200">Zgodność z planem</div>
        <div className="mt-2 grid gap-3 sm:grid-cols-3">
          <Mini label="planned" value={t.planCompliance.planned} />
          <Mini label="modified" value={t.planCompliance.modified} />
          <Mini label="unplanned" value={t.planCompliance.unplanned} />
        </div>
      </div>

      {/* byWeek */}
      <div className="mt-6">
        <div className="text-sm text-slate-200">Tygodnie (ISO)</div>
        <div className="mt-2 overflow-hidden rounded-xl border border-slate-800">
          <table className="w-full text-sm">
            <thead className="bg-slate-950/40 text-slate-300">
              <tr>
                <th className="px-3 py-2 text-left">Tydzień</th>
                <th className="px-3 py-2 text-right">Treningi</th>
                <th className="px-3 py-2 text-right">km</th>
                <th className="px-3 py-2 text-right">min</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {data.byWeek.map((w) => (
                <tr key={w.week} className="text-slate-100">
                  <td className="px-3 py-2 font-mono">{w.week}</td>
                  <td className="px-3 py-2 text-right">{w.workouts}</td>
                  <td className="px-3 py-2 text-right">{w.distanceKm.toFixed(2)}</td>
                  <td className="px-3 py-2 text-right">{w.durationMin.toFixed(2)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* byDay */}
      {data.byDay?.length ? (
        <div className="mt-6">
          <div className="text-sm text-slate-200">Dni</div>
          <div className="mt-2 overflow-hidden rounded-xl border border-slate-800">
            <table className="w-full text-sm">
              <thead className="bg-slate-950/40 text-slate-300">
                <tr>
                  <th className="px-3 py-2 text-left">Dzień</th>
                  <th className="px-3 py-2 text-right">Treningi</th>
                  <th className="px-3 py-2 text-right">km</th>
                  <th className="px-3 py-2 text-right">min</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800">
                {data.byDay.map((d) => (
                  <tr key={d.day} className="text-slate-100">
                    <td className="px-3 py-2 font-mono">{d.day}</td>
                    <td className="px-3 py-2 text-right">{d.workouts}</td>
                    <td className="px-3 py-2 text-right">{d.distanceKm.toFixed(2)}</td>
                    <td className="px-3 py-2 text-right">{d.durationMin.toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : null}
    </section>
  )
}

function Card({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-xl bg-slate-800/70 p-4 shadow-sm ring-1 ring-white/5">
      <p className="text-sm text-slate-300">{label}</p>
      <p className="mt-2 text-xl font-semibold text-white">{value}</p>
    </div>
  )
}

function Mini({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border border-slate-800 bg-slate-950/30 px-3 py-2">
      <div className="text-xs uppercase tracking-wide text-slate-400">{label}</div>
      <div className="mt-1 text-lg font-semibold text-white">{value}</div>
    </div>
  )
}

