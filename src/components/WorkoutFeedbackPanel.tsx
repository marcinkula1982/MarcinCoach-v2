import type { WorkoutFeedback } from '../api/workouts'
import type { Metrics } from '../types'

type WorkoutFeedbackPanelProps = {
  feedback: WorkoutFeedback | null
  metrics: Metrics | null
  isLoading: boolean
  error: string | null
  canGenerate: boolean
  onGenerate: () => void
  onRefresh: () => void
}

const confidenceLabel = (value?: string) => {
  if (value === 'high') return 'wysoka'
  if (value === 'medium') return 'średnia'
  if (value === 'low') return 'niska'
  return value ?? '-'
}

const warningLabel = (key: string) => {
  const labels: Record<string, string> = {
    overloadRisk: 'ryzyko przeciążenia',
    hrInstability: 'niestabilne tętno',
    economyDrop: 'spadek ekonomii',
  }
  return labels[key] ?? key
}

const formatSeconds = (value: number | null | undefined) => {
  if (!Number.isFinite(value ?? NaN) || !value || value <= 0) return '-'
  const hours = Math.floor(value / 3600)
  const minutes = Math.floor((value % 3600) / 60)
  const seconds = Math.floor(value % 60)
  if (hours > 0) return `${hours}h ${minutes.toString().padStart(2, '0')}m`
  return `${minutes}m ${seconds.toString().padStart(2, '0')}s`
}

const formatPace = (value: number | null | undefined) => {
  if (!Number.isFinite(value ?? NaN) || !value || value <= 0) return '-'
  const minutes = Math.floor(value / 60)
  const seconds = Math.round(value % 60)
  return `${minutes}:${seconds.toString().padStart(2, '0')} /km`
}

const formatSignalMetric = (key: string, value: unknown) => {
  if (value === null || value === undefined) return null
  if (typeof value === 'boolean') return value ? 'tak' : 'nie'
  if (typeof value === 'number') {
    if (key === 'paceEquality') return `${Math.round(value * 100)}%`
    if (key === 'hrDrift') return `${value.toFixed(1)} bpm`
    if (key === 'weeklyLoadContribution' || key === 'analysisLoad7d') return `${Math.round(value)} min`
    if (key === 'acwr') return value.toFixed(2)
    return Number.isInteger(value) ? String(value) : value.toFixed(2)
  }
  if (typeof value === 'string') return value
  return null
}

const readableMetricLabel = (key: string) => {
  const labels: Record<string, string> = {
    hrDrift: 'Dryf HR',
    paceEquality: 'Równość tempa',
    weeklyLoadContribution: 'Wkład do load',
    analysisLoad7d: 'Load 7 dni',
    acwr: 'ACWR',
    spikeLoad: 'Load spike',
  }
  return labels[key] ?? key
}

const FeedbackList = ({
  title,
  eyebrow,
  items,
  empty,
}: {
  title: string
  eyebrow: string
  items: string[]
  empty: string
}) => (
  <div className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
    <p className="text-[11px] uppercase tracking-[0.2em] text-indigo-300/80">{eyebrow}</p>
    <h3 className="mt-1 text-sm font-semibold text-white">{title}</h3>
    <ul className="mt-3 space-y-2 text-sm text-slate-200">
      {items.length > 0 ? (
        items.map((item) => <li key={item}>{item}</li>)
      ) : (
        <li className="text-slate-400">{empty}</li>
      )}
    </ul>
  </div>
)

const WorkoutFeedbackPanel = ({
  feedback,
  metrics,
  isLoading,
  error,
  canGenerate,
  onGenerate,
  onRefresh,
}: WorkoutFeedbackPanelProps) => {
  const feedbackSummary = feedback?.summary ?? {}
  const distanceKm =
    typeof metrics?.distanceM === 'number'
      ? metrics.distanceM / 1000
      : feedbackSummary.distanceKm ?? null
  const movingTimeSec =
    typeof metrics?.durationSec === 'number'
      ? metrics.durationSec
      : feedbackSummary.movingTimeSec ?? null
  const avgPaceSecPerKm =
    typeof metrics?.avgPaceSecPerKm === 'number'
      ? metrics.avgPaceSecPerKm
      : feedbackSummary.avgPaceSecPerKm ?? null
  const activeWarnings = Object.entries(feedback?.planImpact.warnings ?? {})
    .filter(([, active]) => active)
    .map(([key]) => warningLabel(key))
  const signalMetrics = Object.entries(feedback?.metrics ?? {})
    .map(([key, value]) => [key, formatSignalMetric(key, value)] as const)
    .filter(([, value]) => value !== null)

  return (
    <section className="mt-8 rounded-2xl bg-slate-900/60 p-6 shadow-lg ring-1 ring-white/5">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p className="text-xs uppercase tracking-[0.2em] text-indigo-300/80">
            Feedback po treningu
          </p>
          <h2 className="mt-1 text-xl font-semibold text-white">
            Co ten trening zmienia w planie
          </h2>
          {feedback?.generatedAtIso && (
            <p className="mt-1 text-sm text-slate-400">
              Ostatnio wygenerowano: {new Date(feedback.generatedAtIso).toLocaleString('pl-PL')}
            </p>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          {feedback && (
            <button
              type="button"
              onClick={onRefresh}
              disabled={isLoading}
              className="min-h-10 rounded-lg border border-slate-700 px-3 py-2 text-sm font-semibold text-slate-100 hover:border-indigo-400 disabled:cursor-not-allowed disabled:opacity-60"
            >
              Odczytaj ponownie
            </button>
          )}
          <button
            type="button"
            onClick={onGenerate}
            disabled={isLoading || !canGenerate}
            className="min-h-10 rounded-lg bg-indigo-500 px-3 py-2 text-sm font-semibold text-white shadow-lg shadow-indigo-950/30 hover:bg-indigo-400 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {feedback ? 'Wygeneruj ponownie' : 'Wygeneruj feedback'}
          </button>
        </div>
      </div>

      {isLoading && (
        <div className="mt-4 rounded-lg border border-indigo-500/30 bg-indigo-500/10 px-3 py-2 text-sm text-indigo-100">
          Przygotowuję feedback...
        </div>
      )}

      {error && (
        <div className="mt-4 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-100">
          {error}
        </div>
      )}

      {!feedback && !isLoading && (
        <div className="mt-5 rounded-lg border border-dashed border-slate-700 bg-slate-950/40 p-5 text-sm text-slate-300">
          Feedback dla tego treningu nie jest jeszcze zapisany.
        </div>
      )}

      {feedback && (
        <div className="mt-6 space-y-5">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
              <p className="text-xs text-slate-400">Dystans</p>
              <p className="mt-1 text-lg font-semibold text-white">
                {typeof distanceKm === 'number' ? `${distanceKm.toFixed(2)} km` : '-'}
              </p>
            </div>
            <div className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
              <p className="text-xs text-slate-400">Czas</p>
              <p className="mt-1 text-lg font-semibold text-white">{formatSeconds(movingTimeSec)}</p>
            </div>
            <div className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
              <p className="text-xs text-slate-400">Średnie tempo</p>
              <p className="mt-1 text-lg font-semibold text-white">{formatPace(avgPaceSecPerKm)}</p>
            </div>
            <div className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
              <p className="text-xs text-slate-400">Confidence</p>
              <p className="mt-1 text-lg font-semibold text-white">{confidenceLabel(feedback.confidence)}</p>
            </div>
          </div>

          <div className="grid gap-3 md:grid-cols-3">
            <div className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
              <p className="text-xs text-slate-400">Charakter</p>
              <p className="mt-1 text-sm font-semibold text-white">{feedbackSummary.character ?? '-'}</p>
            </div>
            <div className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
              <p className="text-xs text-slate-400">Status czasu</p>
              <p className="mt-1 text-sm font-semibold text-white">{feedbackSummary.durationStatus ?? '-'}</p>
            </div>
            <div className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
              <p className="text-xs text-slate-400">Status HR</p>
              <p className="mt-1 text-sm font-semibold text-white">{feedbackSummary.hrStatus ?? '-'}</p>
            </div>
          </div>

          <div className="grid gap-3 lg:grid-cols-3">
            <FeedbackList
              eyebrow="Praise"
              title="Dobra robota"
              items={feedback.praise}
              empty="Brak osobnych pochwał dla tej jednostki."
            />
            <FeedbackList
              eyebrow="Deviations"
              title="Odchylenia"
              items={feedback.deviations}
              empty="Nie widać istotnych odchyleń."
            />
            <FeedbackList
              eyebrow="Conclusions"
              title="Wnioski"
              items={feedback.conclusions}
              empty="Brak dodatkowych wniosków."
            />
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
            <p className="text-[11px] uppercase tracking-[0.2em] text-indigo-300/80">
              PlanImpact
            </p>
            <p className="mt-2 text-sm text-slate-100">{feedback.planImpact.label}</p>
            <div className="mt-3 flex flex-wrap gap-2">
              {activeWarnings.length > 0 ? (
                activeWarnings.map((warning) => (
                  <span
                    key={warning}
                    className="rounded-full border border-amber-500/40 bg-amber-500/10 px-3 py-1 text-xs text-amber-100"
                  >
                    {warning}
                  </span>
                ))
              ) : (
                <span className="rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs text-emerald-100">
                  bez aktywnych ostrzeżeń
                </span>
              )}
            </div>
          </div>

          {signalMetrics.length > 0 && (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {signalMetrics.map(([key, value]) => (
                <div key={key} className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
                  <p className="text-xs text-slate-400">{readableMetricLabel(key)}</p>
                  <p className="mt-1 text-sm font-semibold text-white">{value}</p>
                </div>
              ))}
            </div>
          )}

          {(metrics?.avgHr || metrics?.maxHr) && (
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
                <p className="text-xs text-slate-400">Średnie tętno</p>
                <p className="mt-1 text-sm font-semibold text-white">
                  {metrics?.avgHr ? `${metrics.avgHr} bpm` : '-'}
                </p>
              </div>
              <div className="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
                <p className="text-xs text-slate-400">Maksymalne tętno</p>
                <p className="mt-1 text-sm font-semibold text-white">
                  {metrics?.maxHr ? `${metrics.maxHr} bpm` : '-'}
                </p>
              </div>
            </div>
          )}
        </div>
      )}
    </section>
  )
}

export default WorkoutFeedbackPanel
