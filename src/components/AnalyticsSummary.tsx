import { useEffect, useState } from 'react'
import client from '../api/client'

type Summary = {
  totals: {
    workouts: number
    distanceKm: number
    durationMin: number
    intensity: {
      z1Min: number
      z2Min: number
      z3Min: number
      z4Min: number
      z5Min: number
    }
  }
  byWeek: Array<{
    week: string
    workouts: number
    distanceKm: number
    durationMin: number
    intensity: {
      z1Min: number
      z2Min: number
      z3Min: number
      z4Min: number
      z5Min: number
    }
  }>
  byDay: Array<{
    day: string
    workouts: number
    distanceKm: number
    durationMin: number
    intensity: {
      z1Min: number
      z2Min: number
      z3Min: number
      z4Min: number
      z5Min: number
    }
  }>
}

export default function AnalyticsSummary() {
  const [data, setData] = useState<Summary | null>(null)
  const [error, setError] = useState<string | null>(null)

  // pola zakresu startują puste – pierwszy fetch bez filtrów
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const load = async (useFilters: boolean) => {
    setError(null)
    try {
      const res = await client.get<Summary>('/workouts/analytics/summary-v2', {
        params: useFilters
          ? {
              ...(from ? { from } : {}),
              ...(to ? { to } : {}),
            }
          : undefined,
      })
      setData(res.data)
    } catch (e: any) {
      setData(null)
      setError(e?.response?.data?.message ?? 'Błąd pobierania analytics')
    }
  }

  useEffect(() => {
    load(false)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (error) {
    return (
      <div className="mt-6 rounded border border-red-500/40 bg-red-900/30 p-4 text-sm">
        {error}
      </div>
    )
  }

  if (!data) {
    return <div className="mt-6 text-sm text-slate-400">Ładowanie analytics…</div>
  }

  const totals = data.totals
  const byWeek = data.byWeek ?? []
  const byDay = data.byDay ?? []
  const avgKmPerWorkout =
    totals.workouts > 0 ? totals.distanceKm / totals.workouts : 0
  const avgMinPerWorkout =
    totals.workouts > 0 ? totals.durationMin / totals.workouts : 0

  const topDays = [...byDay]
    .sort((a, b) => (b.distanceKm ?? 0) - (a.distanceKm ?? 0))
    .slice(0, 3)

  // === Focus tygodnia + Wnioski (MVP) ===
  const totalWorkouts = totals?.workouts ?? 0
  const totalKm = totals?.distanceKm ?? 0
  const totalMin = totals?.durationMin ?? 0

  // M2: summary-v2 does not provide planCompliance (will return in TrainingSignals / M3)
  const plan = null
  const unplanned = 0
  const planned = 0
  const modified = 0

  let plannedPct: number | null = null
  let modifiedPct: number | null = null
  let unplannedPct: number | null = null

  // Posortowane tygodnie – kanoniczna kolejność zarówno dla logiki trendu,
  // jak i dla renderowanej tabeli tygodni.
  const weeksSorted = [...byWeek].sort((a, b) => String(a.week).localeCompare(String(b.week)))
  const lastWeek = weeksSorted.length >= 1 ? weeksSorted[weeksSorted.length - 1] : null
  const prevWeek = weeksSorted.length >= 2 ? weeksSorted[weeksSorted.length - 2] : null

  const lastKm = lastWeek?.distanceKm ?? 0
  const prevKm = prevWeek?.distanceKm ?? 0
  const lastMin = lastWeek?.durationMin ?? 0
  const prevMin = prevWeek?.durationMin ?? 0
  const deltaKmPct = prevKm > 0 ? ((lastKm - prevKm) / prevKm) * 100 : null
  const volumeJump = deltaKmPct !== null && deltaKmPct > 30
  const trendBadge =
    deltaKmPct === null ? '—' : deltaKmPct > 0 ? '▲' : deltaKmPct < 0 ? '▼' : '—'

  const focusText = (() => {
    if (totalWorkouts === 0) {
      return 'Zacznij od regularności — 3 spokojne biegi w tygodniu.'
    }
    if (totalWorkouts <= 2) {
      return 'Regularność — celuj w 3 treningi tygodniowo (spokojnie).'
    }

    if (plan != null && unplanned > planned + modified) {
      return 'Planowanie — zaplanuj szkic tygodnia (pon–nd) i trzymaj easy jako bazę.'
    }

    if (totalWorkouts >= 3 && avgMinPerWorkout < 40) {
      return 'Objętość — wydłuż jeden trening easy do 45–60 min.'
    }
    if (totalWorkouts >= 4 && avgMinPerWorkout > 70) {
      return 'Regeneracja — bez dokładania intensywności, trzymaj łatwo.'
    }
    return 'Baza — trzymaj spokojne biegi i dokładność zapisu (meta / plan).'
  })()

  const wnioskiText = (() => {
    const s1 = `W okresie: ${totalWorkouts} treningów, ${totalKm.toFixed(1)} km, ${totalMin.toFixed(0)} min.`
    let s2 = ''
    if (lastWeek && prevWeek) {
      const pct =
        deltaKmPct === null ? '—' : `${deltaKmPct >= 0 ? '+' : ''}${deltaKmPct.toFixed(0)}%`
      s2 = `Ostatni tydzień ${String(lastWeek.week)}: ${lastKm.toFixed(1)} km vs ${prevKm.toFixed(
        1,
      )} km (${pct}); ${lastMin.toFixed(0)} min vs ${prevMin.toFixed(0)} min.`
    }
    const s3 = volumeJump
      ? 'Uwaga: skok objętości >30% — ryzyko przeciążenia, trzymaj easy.'
      : ''

    return [s1, s2, s3].filter(Boolean).slice(0, 3).join(' ')
  })()

  return (
    <section className="mt-10 space-y-6" aria-label="Twoja historia (analytics)">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-xs uppercase tracking-[0.25em] text-indigo-300/80">Twoja historia</p>
          <h2 className="text-xl font-semibold">Analityka (read-only)</h2>
        </div>

        <div className="flex flex-wrap items-end gap-2 text-sm" aria-label="Filtry historii">
          <div className="flex flex-col">
            <label className="text-xs text-slate-400">Od</label>
            <input
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="rounded border border-slate-700 bg-slate-900 px-2 py-1"
            />
          </div>
          <div className="flex flex-col">
            <label className="text-xs text-slate-400">Do</label>
            <input
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="rounded border border-slate-700 bg-slate-900 px-2 py-1"
            />
          </div>
          <button
            onClick={() => load(true)}
            className="rounded bg-slate-700 px-3 py-2 hover:bg-slate-600"
          >
            Odśwież
          </button>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="rounded bg-slate-800 p-4">
          <div className="text-sm text-slate-400">Treningi</div>
          <div className="text-2xl">{totals.workouts}</div>
        </div>
        <div className="rounded bg-slate-800 p-4">
          <div className="text-sm text-slate-400">Dystans</div>
          <div className="text-2xl">{totals.distanceKm.toFixed(1)} km</div>
        </div>
        <div className="rounded bg-slate-800 p-4">
          <div className="text-sm text-slate-400">Czas</div>
          <div className="text-2xl">{totals.durationMin.toFixed(0)} min</div>
        </div>
        <div className="rounded bg-slate-800 p-4">
          <div className="text-sm text-slate-400">Średnio / trening</div>
          <div className="text-2xl">{avgKmPerWorkout.toFixed(1)} km</div>
          <div className="text-sm text-slate-400">{avgMinPerWorkout.toFixed(0)} min</div>
        </div>
      </div>

      {/* Focus tygodnia */}
      <div className="rounded-xl border border-slate-800 bg-slate-900/40 px-4 py-3">
        <div className="text-xs uppercase tracking-wider text-slate-400">Focus tygodnia</div>
        <div className="mt-1 text-sm text-slate-100">{focusText}</div>
      </div>

      {/* Wnioski */}
      <div
        className={`rounded-xl border px-4 py-3 ${
          volumeJump ? 'border-amber-500/40 bg-amber-500/10' : 'border-slate-800 bg-slate-900/40'
        }`}
      >
        <div className="text-xs uppercase tracking-wider text-slate-400">Wnioski</div>
        <div className={`mt-1 text-sm ${volumeJump ? 'text-amber-100' : 'text-slate-200'}`}>
          {wnioskiText || 'Brak szczegółowych wniosków.'}
        </div>
        {plan != null && totalWorkouts > 0 && plannedPct !== null && modifiedPct !== null && unplannedPct !== null && (
          <div className="mt-2 text-xs text-slate-300">
            Plan: {plannedPct}% planned · {modifiedPct}% modified · {unplannedPct}% unplanned
          </div>
        )}
      </div>

      <div>
        <h3 className="mb-2 text-lg font-semibold">Tygodnie</h3>
        <table className="w-full border border-slate-700 text-sm">
          <thead className="bg-slate-800">
            <tr>
              <th className="p-2 text-left">Tydzień</th>
              <th className="p-2 text-right">Treningi</th>
              <th className="p-2 text-right">km</th>
              <th className="p-2 text-right">min</th>
              <th className="p-2 text-center w-10">Trend</th>
            </tr>
          </thead>
          <tbody>
            {weeksSorted.map((w) => (
              <tr key={w.week} className="border-t border-slate-700">
                <td className="p-2">{w.week}</td>
                <td className="p-2 text-right">{w.workouts}</td>
                <td className="p-2 text-right">{w.distanceKm.toFixed(1)}</td>
                <td className="p-2 text-right">{w.durationMin.toFixed(0)}</td>
                <td className="p-2 text-center">
                  {lastWeek && w.week === lastWeek.week ? trendBadge : ''}
                </td>
              </tr>
            ))}
            {weeksSorted.length === 0 && (
              <tr className="border-t border-slate-700">
                <td className="p-2 text-slate-400" colSpan={5}>
                  Brak danych.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div>
        <h3 className="mb-2 text-lg font-semibold">Dni</h3>
        <div className="rounded border border-slate-800 bg-slate-900/40 p-4 text-sm">
          <div className="font-semibold mb-2">Top 3 dni (dystans)</div>
          {topDays.length === 0 ? (
            <div className="text-slate-400">Brak danych.</div>
          ) : (
            <ol className="list-decimal pl-5 space-y-1">
              {topDays.map((d) => (
                <li key={d.day}>
                  {d.day} — {d.distanceKm.toFixed(1)} km ({d.workouts} trening)
                </li>
              ))}
            </ol>
          )}
        </div>
        <table className="w-full border border-slate-700 text-sm">
          <thead className="bg-slate-800">
            <tr>
              <th className="p-2 text-left">Dzień</th>
              <th className="p-2 text-right">Treningi</th>
              <th className="p-2 text-right">km</th>
              <th className="p-2 text-right">min</th>
            </tr>
          </thead>
          <tbody>
            {byDay.map((d) => (
              <tr key={d.day} className="border-t border-slate-700">
                <td className="p-2">{d.day}</td>
                <td className="p-2 text-right">{d.workouts}</td>
                <td className="p-2 text-right">{d.distanceKm.toFixed(1)}</td>
                <td className="p-2 text-right">{d.durationMin.toFixed(0)}</td>
              </tr>
            ))}
            {byDay.length === 0 && (
              <tr className="border-t border-slate-700">
                <td className="p-2 text-slate-400" colSpan={4}>
                  Brak danych.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </section>
  )
}


