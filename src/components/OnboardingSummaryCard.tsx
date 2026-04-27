import { useEffect, useState } from 'react'
import { fetchOnboardingSummary } from '../api/onboarding-summary'
import type {
  OnboardingSummary,
  OnboardingSummaryTone,
} from '../types/onboarding-summary'

type OnboardingSummaryCardProps = {
  refreshToken?: number
}

const toneClasses: Record<OnboardingSummaryTone, string> = {
  good: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100',
  warn: 'border-amber-500/40 bg-amber-500/10 text-amber-100',
  neutral: 'border-slate-700 bg-slate-900/70 text-slate-200',
}

export default function OnboardingSummaryCard({
  refreshToken = 0,
}: OnboardingSummaryCardProps) {
  const [summary, setSummary] = useState<OnboardingSummary | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const controller = new AbortController()
    setError(null)

    fetchOnboardingSummary(90, { signal: controller.signal })
      .then(setSummary)
      .catch((err: any) => {
        if (err?.name === 'CanceledError' || err?.code === 'ERR_CANCELED') return
        setSummary(null)
        setError(err?.response?.data?.message ?? 'Nie udalo sie pobrac podsumowania.')
      })

    return () => controller.abort()
  }, [refreshToken])

  if (error) {
    return (
      <section className="mt-8 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-100">
        {error}
      </section>
    )
  }

  if (!summary) {
    return (
      <section className="mt-8 rounded-xl border border-slate-800 bg-slate-900/50 p-4 text-sm text-slate-400">
        Ladowanie podsumowania...
      </section>
    )
  }

  return (
    <section className="mt-8 rounded-xl border border-slate-800 bg-slate-900/60 p-5 shadow-lg shadow-slate-950/20">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p className="text-xs uppercase tracking-[0.22em] text-emerald-300/80">
            Start po onboardingu
          </p>
          <h2 className="mt-2 text-xl font-semibold text-white">{summary.headline}</h2>
          <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-300">{summary.lead}</p>
        </div>
        <div className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-right">
          <div className="text-xs uppercase tracking-wide text-slate-500">Confidence</div>
          <div className="mt-1 text-sm font-semibold text-slate-100">{summary.confidence}</div>
        </div>
      </div>

      <div className="mt-4 flex flex-wrap gap-2">
        {summary.badges.map((badge) => (
          <span
            key={badge.code}
            className={`rounded-full border px-3 py-1 text-xs font-semibold ${toneClasses[badge.tone]}`}
          >
            {badge.label}
          </span>
        ))}
      </div>

      <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {summary.highlights.map((item) => (
          <div
            key={item.code}
            className={`rounded-lg border p-3 ${toneClasses[item.tone]}`}
          >
            <div className="text-xs opacity-80">{item.label}</div>
            <div className="mt-1 text-lg font-semibold">{item.value}</div>
            <div className="mt-1 text-xs opacity-75">{item.detail}</div>
          </div>
        ))}
      </div>

      <div className="mt-5 rounded-lg border border-slate-800 bg-slate-950/60 p-4">
        <div className="text-xs uppercase tracking-wide text-slate-500">Nastepne kroki</div>
        <div className="mt-3 grid gap-3 md:grid-cols-3">
          {summary.nextSteps.map((step) => (
            <div key={step.code} className="rounded-lg border border-slate-800 bg-slate-900/60 p-3">
              <div className="text-sm font-semibold text-slate-100">{step.label}</div>
              <div className="mt-1 text-xs leading-5 text-slate-400">{step.reason}</div>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}
