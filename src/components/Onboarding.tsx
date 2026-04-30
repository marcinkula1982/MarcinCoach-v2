import { useEffect, useMemo, useState } from 'react'
import type { ChangeEvent, FormEvent } from 'react'
import client from '../api/client'
import type { UserProfile } from '../api/profile'
import { uploadTcxFile } from '../api/workouts'

type Source = 'strava' | 'garmin' | 'tcx' | 'manual'
type ManualLastRun = 'last_7_days' | 'last_30_days' | 'over_30_days' | 'never'
type YesNo = 'yes' | 'no'

type OnboardingProps = {
  onCompleted?: (result?: { skipped?: boolean }) => void
  initialProfile?: UserProfile | null
}

const WEEK_DAYS = [
  { id: 'mon', label: 'Pon' },
  { id: 'tue', label: 'Wt' },
  { id: 'wed', label: 'Sr' },
  { id: 'thu', label: 'Czw' },
  { id: 'fri', label: 'Pt' },
  { id: 'sat', label: 'Sob' },
  { id: 'sun', label: 'Nd' },
]

const SOURCE_OPTIONS: Array<{
  id: Source
  title: string
  text: string
}> = [
  { id: 'strava', title: 'Strava', text: 'OAuth + synchronizacja aktywności' },
  { id: 'garmin', title: 'Garmin', text: 'Connector Garmin Connect' },
  { id: 'tcx', title: 'Pliki TCX', text: 'Upload historii treningów' },
  { id: 'manual', title: 'Bez danych', text: 'Krótka ankieta diagnostyczna' },
]

const FUTURE_SOURCES = [
  { title: 'Polar', text: 'AccessLink' },
  { title: 'Suunto', text: 'Cloud API' },
]

const toNumber = (value: string): number | null => {
  const trimmed = value.trim()
  if (!trimmed) return null
  const parsed = Number(trimmed.replace(',', '.'))
  return Number.isFinite(parsed) ? parsed : null
}

const parseDistanceKm = (goal: string): number | null => {
  const lower = goal.toLowerCase()
  if (lower.includes('polmaraton') || lower.includes('polmaratonu') || lower.includes('half')) return 21.1
  if (lower.includes('półmaraton') || lower.includes('półmaratonu')) return 21.1
  if (lower.includes('maraton')) return 42.2

  const match = lower.match(/(\d+(?:[,.]\d+)?)\s*(km|k)\b/)
  if (!match) return null

  const parsed = Number(match[1].replace(',', '.'))
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null
}

const deriveRunningDays = (countRaw: string, unavailableDays: string[]) => {
  const count = Math.max(1, Math.min(7, toNumber(countRaw) ?? 3))
  const available = WEEK_DAYS
    .map((day) => day.id)
    .filter((day) => !unavailableDays.includes(day))

  return available.slice(0, Math.min(count, available.length || 1))
}

const isSource = (value: unknown): value is Source =>
  value === 'strava' || value === 'garmin' || value === 'tcx' || value === 'manual'

const parseProfileConstraints = (raw: string | null | undefined): Record<string, any> => {
  if (!raw) return {}
  try {
    const parsed = JSON.parse(raw)
    return parsed && typeof parsed === 'object' ? parsed : {}
  } catch {
    return {}
  }
}

export default function Onboarding({ onCompleted, initialProfile = null }: OnboardingProps) {
  const [phase, setPhase] = useState<'source' | 'questions'>('source')
  const [source, setSource] = useState<Source | null>(null)
  const [sourceStatus, setSourceStatus] = useState('')
  const [sourceError, setSourceError] = useState('')
  const [uploadedCount, setUploadedCount] = useState(0)
  const [isSourceBusy, setIsSourceBusy] = useState(false)

  const [goalText, setGoalText] = useState('')
  const [raceDate, setRaceDate] = useState('')
  const [currentPain, setCurrentPain] = useState<YesNo>('no')
  const [painNote, setPainNote] = useState('')
  const [trainingDays, setTrainingDays] = useState('4')
  const [unavailableDays, setUnavailableDays] = useState<string[]>([])

  const [lastRun, setLastRun] = useState<ManualLastRun>('last_30_days')
  const [runsLast2Weeks, setRunsLast2Weeks] = useState('')
  const [longestRunKm, setLongestRunKm] = useState('')
  const [canRun30Min, setCanRun30Min] = useState<YesNo>('yes')

  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isSkipping, setIsSkipping] = useState(false)
  const [formError, setFormError] = useState('')

  const distanceKm = useMemo(() => parseDistanceKm(goalText), [goalText])
  const derivedRunningDays = useMemo(
    () => deriveRunningDays(trainingDays, unavailableDays),
    [trainingDays, unavailableDays],
  )

  useEffect(() => {
    if (!initialProfile) return

    if (typeof initialProfile.goals === 'string') {
      setGoalText(initialProfile.goals)
    }

    const firstRace = Array.isArray(initialProfile.races)
      ? (initialProfile.races[0] as Record<string, any> | undefined)
      : undefined
    if (typeof firstRace?.date === 'string') {
      setRaceDate(firstRace.date)
    }

    const availability = initialProfile.availability ?? {}
    const requestedTrainingDays = availability.requestedTrainingDays
    const runningDays = availability.runningDays
    if (typeof requestedTrainingDays === 'number') {
      setTrainingDays(String(requestedTrainingDays))
    } else if (Array.isArray(runningDays) && runningDays.length > 0) {
      setTrainingDays(String(runningDays.length))
    }
    if (Array.isArray(availability.unavailableDays)) {
      setUnavailableDays(
        availability.unavailableDays.filter((day): day is string => typeof day === 'string'),
      )
    }

    const health = initialProfile.health ?? {}
    setCurrentPain(health.currentPain === true ? 'yes' : 'no')
    if (Array.isArray(health.injuryHistory) && typeof health.injuryHistory[0] === 'string') {
      setPainNote(health.injuryHistory[0])
    }

    const constraints = parseProfileConstraints(initialProfile.constraints)
    const onboarding = constraints.onboarding ?? {}
    const savedSource = onboarding.source === 'skipped' ? 'manual' : onboarding.source
    if (isSource(savedSource)) {
      setSource(savedSource)
      setPhase('questions')
    }
    if (typeof onboarding.uploadedWorkoutsCount === 'number') {
      setUploadedCount(onboarding.uploadedWorkoutsCount)
    }
    if (onboarding.manual && typeof onboarding.manual === 'object') {
      const manual = onboarding.manual
      if (typeof manual.lastRun === 'string') setLastRun(manual.lastRun as ManualLastRun)
      if (typeof manual.runsLast2Weeks === 'number') setRunsLast2Weeks(String(manual.runsLast2Weeks))
      if (typeof manual.longestRunKm === 'number') setLongestRunKm(String(manual.longestRunKm))
      if (typeof manual.canRun30Min === 'boolean') setCanRun30Min(manual.canRun30Min ? 'yes' : 'no')
    }
  }, [initialProfile])

  const toggleUnavailableDay = (day: string) => {
    setUnavailableDays((prev) =>
      prev.includes(day) ? prev.filter((item) => item !== day) : [...prev, day],
    )
  }

  const connectStrava = async () => {
    setSource('strava')
    setSourceError('')
    setSourceStatus('')
    setIsSourceBusy(true)

    try {
      const response = await client.post<{ url?: string }>('/integrations/strava/connect')
      const url = response.data.url
      if (!url) {
        setSourceStatus('Strava zwróciła pusty adres autoryzacji.')
        return
      }

      window.location.href = url
    } catch (error: any) {
      const message = error?.response?.data?.message || error?.message || 'Nie udało się połączyć Stravy.'
      setSourceError(message)
    } finally {
      setIsSourceBusy(false)
    }
  }

  const connectGarmin = async () => {
    setSource('garmin')
    setSourceError('')
    setSourceStatus('')
    setIsSourceBusy(true)

    try {
      await client.post('/integrations/garmin/connect')
      const sync = await client.post('/integrations/garmin/sync')
      const imported = sync.data?.imported ?? 0
      const deduped = sync.data?.deduped ?? 0
      setSourceStatus(`Garmin: pobrano ${imported} nowych, ${deduped} już było.`)
    } catch (error: any) {
      const message = error?.response?.data?.error || error?.response?.data?.message || error?.message || 'Garmin connector niedostępny.'
      setSourceError(message)
    } finally {
      setIsSourceBusy(false)
    }
  }

  const uploadFiles = async (event: ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(event.target.files ?? [])
    if (files.length === 0) return

    setSource('tcx')
    setSourceError('')
    setSourceStatus('')
    setIsSourceBusy(true)

    let imported = 0
    let duplicates = 0
    let failed = 0

    for (const file of files) {
      try {
        await uploadTcxFile(file)
        imported += 1
      } catch (error: any) {
        if (error?.response?.status === 409) {
          duplicates += 1
        } else {
          failed += 1
        }
      }
    }

    setUploadedCount((prev) => prev + imported + duplicates)
    setSourceStatus(`TCX: dodano ${imported}, duplikaty ${duplicates}, błędy ${failed}.`)
    if (failed > 0) {
      setSourceError('Część plików nie przeszła importu TCX.')
    }
    setIsSourceBusy(false)
    event.target.value = ''
  }

  const buildPayload = () => {
    const hasPain = currentPain === 'yes'
    const manualPath = source === 'manual'

    const payload: Record<string, unknown> = {
      goals: goalText.trim(),
      availability: {
        runningDays: derivedRunningDays,
        requestedTrainingDays: toNumber(trainingDays),
        unavailableDays,
      },
      health: {
        currentPain: hasPain,
        injuryHistory: hasPain && painNote.trim() ? [painNote.trim()] : [],
      },
      equipment: {
        watch: source !== 'manual',
        hrSensor: source !== 'manual',
      },
      constraints: JSON.stringify({
        onboarding: {
          source,
          uploadedWorkoutsCount: uploadedCount,
          confidenceHint: source === 'tcx' && uploadedCount < 6 ? 'low_data_sample' : 'standard',
          goalClassification: {
            distanceKm,
            goalType: distanceKm ? 'race_distance' : 'open_text',
            priority: 'A',
          },
          manual: manualPath
            ? {
                lastRun,
                runsLast2Weeks: toNumber(runsLast2Weeks),
                longestRunKm: toNumber(longestRunKm),
                canRun30Min: canRun30Min === 'yes',
              }
            : null,
        },
      }),
    }

    if (raceDate && distanceKm) {
      payload.races = [
        {
          date: raceDate,
          distanceKm,
          priority: 'A',
        },
      ]
    }

    return payload
  }

  const submitProfile = async (event: FormEvent) => {
    event.preventDefault()
    setFormError('')

    if (!source) {
      setFormError('Wybierz źródło danych.')
      setPhase('source')
      return
    }
    if (!goalText.trim()) {
      setFormError('Wpisz cel jednym zdaniem.')
      return
    }
    if (derivedRunningDays.length === 0) {
      setFormError('Zostaw co najmniej jeden dzień dostępny.')
      return
    }

    setIsSubmitting(true)
    try {
      await client.put('/me/profile', buildPayload())
      onCompleted?.({ skipped: false })
    } catch (error: any) {
      const message = error?.response?.data?.message || error?.message || 'Nie udało się zapisać profilu.'
      setFormError(message)
    } finally {
      setIsSubmitting(false)
    }
  }

  const skipOnboarding = async () => {
    setFormError('')
    setIsSkipping(true)

    // Optimistyczne przejście — nie blokujemy usera nawet gdy API zawiedzie
    onCompleted?.({ skipped: true })

    try {
      await client.put('/me/profile', {
        goals: 'Pominieto onboarding',
        availability: {
          runningDays: ['mon', 'wed', 'fri'],
        },
        health: {
          currentPain: false,
          injuryHistory: [],
        },
        equipment: {
          watch: false,
          hrSensor: false,
        },
        constraints: JSON.stringify({
          onboarding: {
            skipped: true,
            source: 'skipped',
            confidenceHint: 'missing_onboarding',
            skippedAt: new Date().toISOString(),
          },
        }),
      })
    } catch (error: any) {
      // Logujemy błąd, ale user już przeszedł dalej
      console.warn('skipOnboarding API error (ignorowany):', error?.response?.data?.message || error?.message)
    } finally {
      setIsSkipping(false)
    }
  }

  return (
    <form onSubmit={submitProfile} className="space-y-6 rounded-xl border border-slate-800 bg-slate-900/60 p-6 text-slate-100 shadow-lg shadow-slate-950/30">
      <div className="flex flex-col gap-3 border-b border-slate-800 pb-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.22em] text-indigo-300">Onboarding</p>
          <h1 className="mt-2 text-2xl font-semibold text-white">Skąd mamy pobrać Twoje treningi?</h1>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex rounded-lg border border-slate-700 bg-slate-950 p-1 text-sm">
            <button
              type="button"
              onClick={() => setPhase('source')}
              className={`rounded-md px-3 py-1.5 ${phase === 'source' ? 'bg-indigo-500 text-white' : 'text-slate-300'}`}
            >
              Źródło
            </button>
            <button
              type="button"
              onClick={() => source && setPhase('questions')}
              className={`rounded-md px-3 py-1.5 ${phase === 'questions' ? 'bg-indigo-500 text-white' : 'text-slate-300'}`}
            >
              Pytania
            </button>
          </div>
          <button
            type="button"
            onClick={skipOnboarding}
            disabled={isSubmitting || isSkipping}
            className="rounded-lg border border-slate-700 px-3 py-2 text-sm font-semibold text-slate-200 hover:border-slate-500 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {isSkipping ? 'Pomijanie...' : 'Pomiń'}
          </button>
        </div>
      </div>

      {phase === 'source' && (
        <section className="space-y-5">
          <div className="grid gap-3 md:grid-cols-2">
            {SOURCE_OPTIONS.map((option) => {
              const active = source === option.id
              return (
                <button
                  key={option.id}
                  type="button"
                  onClick={() => {
                    setSource(option.id)
                    setSourceError('')
                    setSourceStatus('')
                  }}
                  className={`min-h-28 rounded-lg border p-4 text-left transition ${
                    active
                      ? 'border-indigo-400 bg-indigo-500/15 text-white'
                      : 'border-slate-700 bg-slate-950/60 text-slate-200 hover:border-slate-500'
                  }`}
                >
                  <span className="text-lg font-semibold">{option.title}</span>
                  <span className="mt-2 block text-sm text-slate-400">{option.text}</span>
                </button>
              )
            })}
          </div>

          <div className="grid gap-3 md:grid-cols-2">
            {FUTURE_SOURCES.map((option) => (
              <div key={option.title} className="min-h-20 rounded-lg border border-slate-800 bg-slate-950/40 p-4 text-slate-500">
                <div className="text-base font-semibold">{option.title}</div>
                <div className="mt-1 text-sm">{option.text}</div>
              </div>
            ))}
          </div>

          {source === 'strava' && (
            <div className="rounded-lg border border-slate-700 bg-slate-950/60 p-4">
              <button
                type="button"
                onClick={connectStrava}
                disabled={isSourceBusy}
                className="rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
              >
                Polacz Strave
              </button>
            </div>
          )}

          {source === 'garmin' && (
            <div className="rounded-lg border border-slate-700 bg-slate-950/60 p-4">
              <button
                type="button"
                onClick={connectGarmin}
                disabled={isSourceBusy}
                className="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
              >
                Polacz Garmina
              </button>
            </div>
          )}

          {source === 'tcx' && (
            <div className="rounded-lg border border-slate-700 bg-slate-950/60 p-4">
              <label className="inline-flex cursor-pointer rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                <input
                  type="file"
                  accept=".tcx,application/vnd.garmin.tcx+xml"
                  multiple
                  className="hidden"
                  onChange={uploadFiles}
                  disabled={isSourceBusy}
                />
                Wgraj pliki TCX
              </label>
              <div className="mt-3 text-sm text-slate-400">Zaimportowane w onboardingu: {uploadedCount}</div>
            </div>
          )}

          {source === 'manual' && (
            <div className="rounded-lg border border-slate-700 bg-slate-950/60 p-4 text-sm text-slate-300">
              Przejdziesz przez krótką diagnostykę bez danych treningowych.
            </div>
          )}

          {(sourceStatus || sourceError) && (
            <div className="space-y-2 text-sm">
              {sourceStatus && <div className="rounded-md border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-emerald-100">{sourceStatus}</div>}
              {sourceError && <div className="rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-amber-100">{sourceError}</div>}
            </div>
          )}

          <div className="flex justify-end">
            <button
              type="button"
              disabled={!source}
              onClick={() => setPhase('questions')}
              className="rounded-lg bg-indigo-500 px-5 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-400"
            >
              Dalej
            </button>
          </div>
        </section>
      )}

      {phase === 'questions' && (
        <section className="space-y-5">
          <div className="grid gap-4 md:grid-cols-2">
            <label className="space-y-2 md:col-span-2">
              <span className="text-sm font-medium text-slate-300">Cel</span>
              <textarea
                value={goalText}
                onChange={(event) => setGoalText(event.target.value)}
                rows={3}
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-400"
                placeholder="Np. przebiec 10 km poniżej 50 minut"
              />
            </label>

            <label className="space-y-2">
              <span className="text-sm font-medium text-slate-300">Data startu</span>
              <input
                type="date"
                value={raceDate}
                onChange={(event) => setRaceDate(event.target.value)}
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-400"
              />
            </label>

            <label className="space-y-2">
              <span className="text-sm font-medium text-slate-300">Dni treningowe w tygodniu</span>
              <input
                inputMode="numeric"
                value={trainingDays}
                onChange={(event) => setTrainingDays(event.target.value)}
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-400"
                placeholder="4"
              />
            </label>

            <label className="space-y-2">
              <span className="text-sm font-medium text-slate-300">Czy coś teraz boli?</span>
              <select
                value={currentPain}
                onChange={(event) => setCurrentPain(event.target.value as YesNo)}
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-400"
              >
                <option value="no">Nie</option>
                <option value="yes">Tak</option>
              </select>
            </label>

            <label className="space-y-2">
              <span className="text-sm font-medium text-slate-300">Opis bólu</span>
              <input
                value={painNote}
                onChange={(event) => setPainNote(event.target.value)}
                disabled={currentPain === 'no'}
                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-400 disabled:opacity-50"
                placeholder="Lokalizacja / kiedy się pojawia"
              />
            </label>
          </div>

          <div className="space-y-3">
            <div className="text-sm font-medium text-slate-300">Dni bez biegania</div>
            <div className="flex flex-wrap gap-2">
              {WEEK_DAYS.map((day) => {
                const checked = unavailableDays.includes(day.id)
                return (
                  <label
                    key={day.id}
                    className={`inline-flex min-w-16 items-center justify-center rounded-lg border px-3 py-2 text-sm ${
                      checked
                        ? 'border-amber-400 bg-amber-500/10 text-amber-100'
                        : 'border-slate-700 bg-slate-950 text-slate-300'
                    }`}
                  >
                    <input
                      type="checkbox"
                      checked={checked}
                      onChange={() => toggleUnavailableDay(day.id)}
                      className="sr-only"
                    />
                    {day.label}
                  </label>
                )
              })}
            </div>
            <div className="text-xs text-slate-500">Planowane dni: {derivedRunningDays.join(', ') || 'brak'}</div>
          </div>

          {source === 'manual' && (
            <div className="grid gap-4 rounded-lg border border-slate-700 bg-slate-950/60 p-4 md:grid-cols-2">
              <label className="space-y-2">
                <span className="text-sm font-medium text-slate-300">Ostatni bieg</span>
                <select
                  value={lastRun}
                  onChange={(event) => setLastRun(event.target.value as ManualLastRun)}
                  className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-400"
                >
                  <option value="last_7_days">W ostatnich 7 dniach</option>
                  <option value="last_30_days">W ostatnich 30 dniach</option>
                  <option value="over_30_days">Ponad 30 dni temu</option>
                  <option value="never">Nie biegalem regularnie</option>
                </select>
              </label>

              <label className="space-y-2">
                <span className="text-sm font-medium text-slate-300">Biegi w ostatnich 2 tygodniach</span>
                <input
                  inputMode="numeric"
                  value={runsLast2Weeks}
                  onChange={(event) => setRunsLast2Weeks(event.target.value)}
                  className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-400"
                  placeholder="3"
                />
              </label>

              <label className="space-y-2">
                <span className="text-sm font-medium text-slate-300">Najdłuższy bieg w miesiącu</span>
                <input
                  inputMode="decimal"
                  value={longestRunKm}
                  onChange={(event) => setLongestRunKm(event.target.value)}
                  className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-400"
                  placeholder="8 km"
                />
              </label>

              <label className="space-y-2">
                <span className="text-sm font-medium text-slate-300">30 minut bez przerwy</span>
                <select
                  value={canRun30Min}
                  onChange={(event) => setCanRun30Min(event.target.value as YesNo)}
                  className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-400"
                >
                  <option value="yes">Tak</option>
                  <option value="no">Nie</option>
                </select>
              </label>
            </div>
          )}

          {formError && (
            <div className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-100">
              {formError}
            </div>
          )}

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <button
              type="button"
              onClick={() => setPhase('source')}
              className="rounded-lg border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 hover:border-slate-500"
            >
              Wstecz
            </button>
            <button
              type="submit"
              disabled={isSubmitting}
              className="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-400"
            >
              {isSubmitting ? 'Zapisywanie...' : 'Zapisz profil'}
            </button>
          </div>
        </section>
      )}
    </form>
  )
}
