import { useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import {
  createManualCheckIn,
  type ManualCheckInPayload,
  type ManualCheckInResponse,
  type ManualCheckInStatus,
} from '../api/workouts'
import type { PlannedSession } from '../types/weekly-plan'

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

const STATUS_OPTIONS: Array<{
  value: ManualCheckInStatus
  label: string
  hint: string
}> = [
  {
    value: 'done',
    label: 'Wykonane',
    hint: 'Trening poszedł zasadniczo zgodnie z planem.',
  },
  {
    value: 'modified',
    label: 'Zmienione',
    hint: 'Trening był krótszy, dłuższy albo odczuwalnie inny.',
  },
  {
    value: 'skipped',
    label: 'Nie zrobiłem',
    hint: 'Dzień zostanie zamknięty bez tworzenia treningu 0 km.',
  },
]

const MOOD_OPTIONS = [
  { value: '', label: 'Nie wybieram' },
  { value: 'good', label: 'Dobrze' },
  { value: 'ok', label: 'Neutralnie' },
  { value: 'tired', label: 'Zmęczenie' },
  { value: 'stressed', label: 'Stres' },
]

const SKIP_REASON_OPTIONS = [
  { value: '', label: 'Nie wybieram' },
  { value: 'no_time', label: 'Brak czasu' },
  { value: 'fatigue', label: 'Zmęczenie' },
  { value: 'pain', label: 'Ból / kontuzja' },
  { value: 'illness', label: 'Choroba' },
  { value: 'other', label: 'Inne' },
]

const cleanText = (value: string) => {
  const trimmed = value.trim()
  return trimmed.length > 0 ? trimmed : undefined
}

const positiveNumber = (value: string) => {
  if (!value.trim()) return undefined
  const parsed = Number(value.replace(',', '.'))
  return Number.isFinite(parsed) && parsed > 0 ? parsed : undefined
}

const positiveInt = (value: string) => {
  const parsed = positiveNumber(value)
  return parsed === undefined ? undefined : Math.round(parsed)
}

const sessionPayload = (session: PlannedSession, plannedDate: string): Record<string, unknown> => {
  const payload: Record<string, unknown> = {
    day: session.day,
    type: session.type,
    durationMin: session.durationMin,
    dateIso: session.dateIso ?? plannedDate,
  }

  if (session.id) payload.id = session.id
  if (typeof session.weekIndex === 'number') payload.weekIndex = session.weekIndex
  if (typeof session.distanceKm === 'number') payload.distanceKm = session.distanceKm
  if (session.intensityHint) payload.intensityHint = session.intensityHint
  if (session.surfaceHint) payload.surfaceHint = session.surfaceHint
  if (session.structure) payload.structure = session.structure
  if (session.notes) payload.notes = session.notes
  if (session.blocks) payload.blocks = session.blocks
  if (session.sportKind) payload.sportKind = session.sportKind
  if (session.sportSubtype) payload.sportSubtype = session.sportSubtype
  if (session.source) payload.source = session.source
  if (session.activityImpact) payload.activityImpact = session.activityImpact

  return payload
}

type ManualCheckInDialogProps = {
  session: PlannedSession
  plannedDate: string
  plannedSessionId?: string
  initialStatus: ManualCheckInStatus
  onClose: () => void
  onSaved?: (response: ManualCheckInResponse) => void | Promise<void>
}

export default function ManualCheckInDialog({
  session,
  plannedDate,
  plannedSessionId,
  initialStatus,
  onClose,
  onSaved,
}: ManualCheckInDialogProps) {
  const [status, setStatus] = useState<ManualCheckInStatus>(initialStatus)
  const [durationMin, setDurationMin] = useState('')
  const [distanceKm, setDistanceKm] = useState('')
  const [rpe, setRpe] = useState('')
  const [mood, setMood] = useState('')
  const [painFlag, setPainFlag] = useState(false)
  const [painNote, setPainNote] = useState('')
  const [note, setNote] = useState('')
  const [skipReason, setSkipReason] = useState('')
  const [modificationReason, setModificationReason] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)

  useEffect(() => {
    setStatus(initialStatus)
    setDurationMin(initialStatus === 'skipped' ? '' : String(session.durationMin || ''))
    setDistanceKm('')
    setRpe('')
    setMood('')
    setPainFlag(false)
    setPainNote('')
    setNote('')
    setSkipReason('')
    setModificationReason('')
    setSubmitError(null)
  }, [initialStatus, plannedDate, session])

  const plannedLabel = useMemo(() => {
    const type = SESSION_TYPE_PL[session.type] ?? session.type
    return `${plannedDate} · ${type} · ${session.durationMin} min`
  }, [plannedDate, session.durationMin, session.type])

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setIsSubmitting(true)
    setSubmitError(null)

    const actualDurationMin = positiveInt(durationMin)
    const parsedDistanceKm = positiveNumber(distanceKm)
    const parsedRpe = rpe ? Number(rpe) : undefined
    const painSelected = painFlag || skipReason === 'pain'

    const planModifications: Array<Record<string, unknown>> = []
    if (
      status === 'modified' &&
      actualDurationMin !== undefined &&
      session.durationMin > 0 &&
      actualDurationMin !== session.durationMin
    ) {
      planModifications.push({
        field: 'durationMin',
        planned: session.durationMin,
        actual: actualDurationMin,
      })
    }

    const payload: ManualCheckInPayload = {
      plannedSessionDate: plannedDate,
      status,
      plannedSession: sessionPayload(session, plannedDate),
      plannedType: session.type,
      plannedDurationMin: session.durationMin,
      painFlag: painSelected,
      ...(plannedSessionId ? { plannedSessionId } : {}),
      ...(session.intensityHint ? { plannedIntensity: session.intensityHint } : {}),
      ...(session.type === 'cross_training'
        ? { sport: session.sportKind ?? 'other' }
        : { sport: 'run' }),
      ...(mood ? { mood } : {}),
      ...(cleanText(painNote) ? { painNote: cleanText(painNote) } : {}),
      ...(cleanText(note) ? { note: cleanText(note) } : {}),
    }

    if (status === 'skipped') {
      if (skipReason) payload.skipReason = skipReason
    } else {
      if (actualDurationMin !== undefined) payload.actualDurationMin = actualDurationMin
      if (parsedDistanceKm !== undefined) payload.distanceKm = parsedDistanceKm
      if (parsedRpe !== undefined) payload.rpe = parsedRpe
    }

    if (status === 'modified') {
      if (cleanText(modificationReason)) {
        payload.modificationReason = cleanText(modificationReason)
      }
      if (planModifications.length > 0) {
        payload.planModifications = planModifications
      }
    }

    try {
      const result = await createManualCheckIn(payload)
      await Promise.resolve(onSaved?.(result))
      onClose()
    } catch (error: unknown) {
      const maybeError = error as {
        response?: { data?: { message?: string; error?: string } }
        message?: string
      }
      const message =
        maybeError.response?.data?.message ||
        maybeError.response?.data?.error ||
        maybeError.message ||
        'Nie udało się zapisać check-inu'
      setSubmitError(String(message))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 px-4">
      <form
        onSubmit={handleSubmit}
        className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-slate-700 bg-slate-900 p-5 shadow-2xl"
      >
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-xs uppercase tracking-[0.2em] text-indigo-300/80">
              Manual check-in
            </p>
            <h3 className="mt-1 text-lg font-semibold text-white">{plannedLabel}</h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800"
          >
            Zamknij
          </button>
        </div>

        <div className="mt-5 grid gap-2 sm:grid-cols-3">
          {STATUS_OPTIONS.map((option) => {
            const selected = status === option.value
            return (
              <button
                key={option.value}
                type="button"
                onClick={() => setStatus(option.value)}
                className={`rounded-lg border px-3 py-3 text-left transition ${
                  selected
                    ? 'border-indigo-400 bg-indigo-500/20 text-white'
                    : 'border-slate-700 bg-slate-950/40 text-slate-300 hover:border-slate-500'
                }`}
              >
                <span className="block text-sm font-semibold">{option.label}</span>
                <span className="mt-1 block text-xs text-slate-400">{option.hint}</span>
              </button>
            )
          })}
        </div>

        {status !== 'skipped' && (
          <div className="mt-5 grid gap-4 sm:grid-cols-2">
            <label className="space-y-2">
              <span className="text-sm text-slate-300">Czas trwania (min)</span>
              <input
                type="number"
                min={1}
                max={600}
                value={durationMin}
                onChange={(event) => setDurationMin(event.target.value)}
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
              />
            </label>
            <label className="space-y-2">
            <span className="text-sm text-slate-300">Dystans (km, opcjonalnie)</span>
              <input
                type="number"
                min={0}
                max={500}
                step="0.01"
                value={distanceKm}
                onChange={(event) => setDistanceKm(event.target.value)}
                placeholder="np. 6.5"
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
              />
            </label>
            <label className="space-y-2">
              <span className="text-sm text-slate-300">RPE (1-10, opcjonalnie)</span>
              <select
                value={rpe}
                onChange={(event) => setRpe(event.target.value)}
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
              >
                <option value="">Nie wybieram</option>
                {Array.from({ length: 10 }, (_, index) => index + 1).map((value) => (
                  <option key={value} value={value}>
                    {value}
                  </option>
                ))}
              </select>
            </label>
            <label className="space-y-2">
              <span className="text-sm text-slate-300">Samopoczucie</span>
              <select
                value={mood}
                onChange={(event) => setMood(event.target.value)}
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
              >
                {MOOD_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
          </div>
        )}

        {status === 'modified' && (
          <label className="mt-4 block space-y-2">
            <span className="text-sm text-slate-300">Co się zmieniło?</span>
            <textarea
              value={modificationReason}
              onChange={(event) => setModificationReason(event.target.value.slice(0, 1000))}
              rows={3}
              placeholder="Np. skróciłem trening, bo nogi były ciężkie."
              className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
            />
          </label>
        )}

        {status === 'skipped' && (
          <div className="mt-5 grid gap-4 sm:grid-cols-2">
            <label className="space-y-2">
              <span className="text-sm text-slate-300">Powód</span>
              <select
                value={skipReason}
                onChange={(event) => {
                  setSkipReason(event.target.value)
                  if (event.target.value === 'pain') setPainFlag(true)
                }}
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
              >
                {SKIP_REASON_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
            <label className="space-y-2">
              <span className="text-sm text-slate-300">Samopoczucie</span>
              <select
                value={mood}
                onChange={(event) => setMood(event.target.value)}
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
              >
                {MOOD_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
          </div>
        )}

        <div className="mt-5 space-y-3">
          <label className="flex items-start gap-3 rounded-lg border border-slate-700 bg-slate-950/40 p-3 text-sm text-slate-200">
            <input
              type="checkbox"
              checked={painFlag}
              onChange={(event) => setPainFlag(event.target.checked)}
              className="mt-1 h-4 w-4 rounded border-slate-600 bg-slate-900"
            />
            <span>
              <span className="block font-semibold text-white">Ból albo kontuzja</span>
              <span className="text-xs text-slate-400">
                Zaznaczenie tego pola może złagodzić kolejne dni planu.
              </span>
            </span>
          </label>

          {painFlag && (
            <label className="block space-y-2">
              <span className="text-sm text-slate-300">Opis bólu</span>
              <textarea
                value={painNote}
                onChange={(event) => setPainNote(event.target.value.slice(0, 1000))}
                rows={2}
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
              />
            </label>
          )}

          <label className="block space-y-2">
            <span className="text-sm text-slate-300">Notatka</span>
            <textarea
              value={note}
              onChange={(event) => setNote(event.target.value.slice(0, 2000))}
              rows={3}
              placeholder="Kilka słów o tym, jak poszło."
              className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
            />
          </label>
        </div>

        {submitError && (
          <div className="mt-4 rounded-lg border border-red-500/40 bg-red-900/30 px-3 py-2 text-sm text-red-100">
            {submitError}
          </div>
        )}

        <div className="mt-6 flex flex-wrap justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            disabled={isSubmitting}
            className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-slate-100 hover:bg-slate-700 disabled:opacity-60"
          >
            Anuluj
          </button>
          <button
            type="submit"
            disabled={isSubmitting}
            className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-60"
          >
            {isSubmitting ? 'Zapisywanie...' : 'Zapisz check-in'}
          </button>
        </div>
      </form>
    </div>
  )
}
