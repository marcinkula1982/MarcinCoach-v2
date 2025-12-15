import { useEffect, useMemo, useState, useCallback } from 'react'
import type { ChangeEvent } from 'react'
import { computeMetrics } from './utils/metrics'
import { parseTcx } from './utils/tcxParser'
import type {
  ParsedTcx,
  RaceMeta,
  WorkoutKind,
} from './types'
import {
  getWorkouts,
  getWorkout,
  deleteWorkout,
  uploadTcxFile,
  updateWorkoutMeta,
  type WorkoutListItem,
  getWorkoutDate,
} from './api/workouts'
import { login } from './api/auth'
import client from './api/client'
import WorkoutsList from './components/WorkoutsList'
import AnalyticsSummary from './components/AnalyticsSummary'

// ---------- Format helpers ----------
const formatSeconds = (value: number) => {
  if (!Number.isFinite(value) || value <= 0) return '0:00'
  const hours = Math.floor(value / 3600)
  const minutes = Math.floor((value % 3600) / 60)
  const seconds = Math.floor(value % 60)
  const mmss = `${minutes.toString().padStart(2, '0')}:${seconds
    .toString()
    .padStart(2, '0')}`
  return hours > 0 ? `${hours}:${mmss}` : mmss
}

const formatPace = (paceSecPerKm: number | null) => {
  if (!paceSecPerKm || paceSecPerKm <= 0) return '–'
  const minutes = Math.floor(paceSecPerKm / 60)
  const seconds = Math.round(paceSecPerKm % 60)
  return `${minutes}:${seconds.toString().padStart(2, '0')} /km`
}

// ---------- UI components ----------
const MetricCard = ({ label, value }: { label: string; value: string | number }) => (
  <div className="rounded-xl bg-slate-800/70 p-4 shadow-sm ring-1 ring-white/5">
    <p className="text-sm text-slate-300">{label}</p>
    <p className="mt-2 text-xl font-semibold text-white">{value}</p>
  </div>
)

const FilePicker = ({
  onChange,
  disabled,
}: {
  onChange: (file: File) => void
  disabled: boolean
}) => {
  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (file) onChange(file)
  }

  return (
    <label className="inline-flex cursor-pointer items-center gap-3 rounded-lg border border-slate-700 bg-slate-800 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:border-indigo-400 hover:text-indigo-100">
      <input
        type="file"
        accept=".tcx,application/vnd.garmin.tcx+xml"
        className="hidden"
        onChange={handleChange}
        disabled={disabled}
      />
      <span>Wybierz plik TCX</span>
    </label>
  )
}

// ---------- Main hook ----------
const useTrimmedSelection = (parsed: ParsedTcx | null, startIndex: number, endIndex: number) =>
  useMemo(() => {
    if (!parsed) return null
    const count = parsed.trackpoints.length
    if (count === 0) {
      return { start: 0, end: 0, trackpoints: [], metrics: computeMetrics([]) }
    }

    const clamp = (v: number, min: number, max: number) => Math.min(Math.max(v, min), max)

    const safeStart = clamp(startIndex, 0, count - 1)
    const safeEnd = clamp(endIndex, safeStart, count - 1)
    const trackpoints = parsed.trackpoints.slice(safeStart, safeEnd + 1)

    return {
      start: safeStart,
      end: safeEnd,
      trackpoints,
      metrics: computeMetrics(trackpoints),
    }
  }, [parsed, startIndex, endIndex])

// ======================================================
//                   MAIN COMPONENT
// ======================================================

const App = () => {
  const API_BASE_URL = import.meta.env.VITE_API_BASE_URL as string
  const [backendHealth, setBackendHealth] = useState<string>('(sprawdzam...)')
  const [username, setUsername] = useState<string>(() => {
    return localStorage.getItem('tcx-username') || ''
  })
  const [password, setPassword] = useState<string>('')
  const [sessionToken, setSessionToken] = useState<string | null>(() => {
    return localStorage.getItem('tcx-session-token')
  })
  const [loggedInUser, setLoggedInUser] = useState<string | null>(() => {
    return localStorage.getItem('tcx-username') || null
  })
  const [currentFileName, setCurrentFileName] = useState<string | null>(null)
  const [currentFile, setCurrentFile] = useState<File | null>(null)
  const [currentWorkoutDate, setCurrentWorkoutDate] = useState<string | null>(null)
  const [currentWorkoutId, setCurrentWorkoutId] = useState<string | null>(null)
  const [parsed, setParsed] = useState<ParsedTcx | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [startIndex, setStartIndex] = useState(0)
  const [endIndex, setEndIndex] = useState(0)
  const [isParsing, setIsParsing] = useState(false)
  const [kind, setKind] = useState<WorkoutKind>('training')
  const [raceMeta, setRaceMeta] = useState<RaceMeta>({
    name: '',
    distance: '10 km',
    priority: 'B',
    customDistance: '',
  })
  const [rawTcx, setRawTcx] = useState<string | null>(null)
  const [isSaving, setIsSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [saveSuccess, setSaveSuccess] = useState<string | null>(null)
  const [workouts, setWorkouts] = useState<WorkoutListItem[]>([])
  const [planCompliance, setPlanCompliance] = useState<'planned' | 'modified' | 'unplanned'>('unplanned')
  const [rpe, setRpe] = useState<number | null>(null)
  const [fatigueWarning, setFatigueWarning] = useState<string | null>(null)
  const [note, setNote] = useState('')
  const [suggestion, setSuggestion] = useState<'planned' | 'modified' | 'unplanned' | null>(null)
  const [suggestionReason, setSuggestionReason] = useState<string | null>(null)

  useEffect(() => {
    if (sessionToken) {
      client.defaults.headers.common['x-session-token'] = sessionToken
    } else {
      delete client.defaults.headers.common['x-session-token']
    }

    if (loggedInUser) {
      client.defaults.headers.common['x-user-id'] = loggedInUser
    } else {
      delete client.defaults.headers.common['x-user-id']
    }
  }, [sessionToken, loggedInUser])

  useEffect(() => {
    const t = localStorage.getItem('tcx-session-token')
    const u = localStorage.getItem('tcx-username')

    if (t && u) {
      client.defaults.headers.common['x-session-token'] = t
      client.defaults.headers.common['x-user-id'] = u

      setSessionToken(t)
      setLoggedInUser(u)
    }
  }, [])

  useEffect(() => {
    client.get('/health')
      .then((res) => {
        setBackendHealth(`${res.status} ${JSON.stringify(res.data)}`)
      })
      .catch((e) => setBackendHealth(`ERR ${String(e)}`))
  }, [])

  const baseMetrics = useMemo(
    () => (parsed ? computeMetrics(parsed.trackpoints) : null),
    [parsed],
  )

  const hasActiveWorkout = Boolean(parsed)

  const trimmed = useTrimmedSelection(parsed, startIndex, endIndex)

  // ---------- Handlers ----------
  const loadTcx = async (raw: string, name?: string) => {
    setError(null)
    setSaveError(null)
    setSaveSuccess(null)
    try {
      const result = parseTcx(raw)
      if (result.trackpoints.length === 0) {
        throw new Error('Brak trackpointów w pliku.')
      }

      setParsed(result)
      setRawTcx(raw)
      if (name) setCurrentFileName(name)
      setStartIndex(0)
      setEndIndex(result.trackpoints.length - 1)
    } catch (err) {
      const message =
        err instanceof Error ? err.message : 'Nie udało się sparsować pliku.'
      setError(message)
      setParsed(null)
      setRawTcx(null)
    }
  }

  const handleFile = async (file: File) => {
    setIsParsing(true)
    try {
      if (file) {
        setCurrentFileName(file.name)
        setCurrentFile(file)
        setCurrentWorkoutDate(null)
        setCurrentWorkoutId(null)
      }
      const content = await file.text()
      await loadTcx(content, file.name)
    } finally {
      setIsParsing(false)
    }
  }

  const summary = useMemo(() => {
    if (!parsed || !baseMetrics || !trimmed) return null
    return {
      fileName: currentFileName ?? undefined,
      startTimeIso: parsed?.startTimeIso ?? null,
      original: baseMetrics,
      trimmed: trimmed.metrics,
      totalPoints: parsed.trackpoints.length,
      selectedPoints: trimmed.trackpoints.length,
    }
  }, [parsed, baseMetrics, trimmed, currentFileName])

  // Soft suggestion logic
  useEffect(() => {
    if (!summary || rpe === null) {
      setSuggestion(null)
      return
    }

    const durationMin = summary.trimmed?.durationSec
      ? summary.trimmed.durationSec / 60
      : summary.original?.durationSec
      ? summary.original.durationSec / 60
      : 0

    const distanceKm = summary.trimmed?.distanceM
      ? summary.trimmed.distanceM / 1000
      : summary.original?.distanceM
      ? summary.original.distanceM / 1000
      : 0

    const fatigueFlag =
      rpe >= 7 &&
      (durationMin < 45 || distanceKm < 8)

    if (rpe <= 3) {
      setSuggestion('unplanned')
    } else if (rpe >= 7 && fatigueFlag) {
      setSuggestion('modified')
    } else {
      setSuggestion(null)
    }
  }, [summary, rpe])

  const loadWorkouts = useCallback(
    async () => {
      try {
        const data = await getWorkouts()
        setWorkouts(data)
      } catch (err) {
        console.warn('Nie udało się pobrać workoutów', err)
      }
    },
    [],
  )

  useEffect(() => {
    const t = localStorage.getItem('tcx-session-token')
    const u = localStorage.getItem('tcx-username')

    if (!t || !u) {
      setWorkouts([])
      return
    }

    loadWorkouts()
  }, [loggedInUser, loadWorkouts])

  const handleLogout = () => {
    setSessionToken(null)
    setLoggedInUser(null)
    localStorage.removeItem('tcx-session-token')
    localStorage.removeItem('tcx-username')
    setPassword('')
    setWorkouts([])
    setCurrentFileName(null)
    setCurrentWorkoutDate(null)
    setParsed(null)
    setRawTcx(null)
    setStartIndex(0)
    setEndIndex(0)
    setSaveError(null)
    setSaveSuccess(null)
  }

  const handleLogin = async () => {
    try {
      const result = await login(username, password)
      setSessionToken(result.sessionToken)
      setLoggedInUser(result.username)
      localStorage.setItem('tcx-session-token', result.sessionToken)
      localStorage.setItem('tcx-username', result.username)
      client.defaults.headers.common['x-session-token'] = result.sessionToken
      client.defaults.headers.common['x-user-id'] = result.username
      setPassword('')
    } catch (err) {
      console.error('Login failed', err)
    }
  }

  const handleSaveToBackend = async () => {
    const t = localStorage.getItem('tcx-session-token')
    const u = localStorage.getItem('tcx-username')
    if (!t || !u) {
      console.error('Not logged in - cannot save workout')
      return
    }

    setIsSaving(true)
    setSaveError(null)
    setSaveSuccess(null)

    try {
      const durationMin = summary?.trimmed?.durationSec
        ? summary.trimmed.durationSec / 60
        : summary?.original?.durationSec
        ? summary.original.durationSec / 60
        : 0

      const distanceKm = summary?.trimmed?.distanceM
        ? summary.trimmed.distanceM / 1000
        : summary?.original?.distanceM
        ? summary.original.distanceM / 1000
        : 0

      const fatigueFlag =
        rpe !== null &&
        rpe >= 7 &&
        (durationMin < 45 || distanceKm < 8)

      const trimmedNote = note.trim()

      const suggestionReason =
        suggestion === 'unplanned'
          ? 'rpe_low_easy'
          : suggestion === 'modified'
          ? 'rpe_high_low_load'
          : null

      const workoutMeta = {
        planCompliance,
        rpe,
        fatigueFlag,
        ...(suggestion ? { suggestedPlanCompliance: suggestion } : {}),
        ...(suggestionReason ? { suggestionReason } : {}),
        ...(planCompliance !== 'planned' && trimmedNote ? { note: trimmedNote } : {}),
      }

      if (currentWorkoutId) {
        // Update existing workout meta
        try {
          await updateWorkoutMeta(Number(currentWorkoutId), workoutMeta)
          const fresh = await getWorkouts()
          setWorkouts(fresh)
          setNote('')
          setSaveSuccess('WorkoutMeta zaktualizowane')
          return
        } catch (error: any) {
          const msg = error?.response?.data?.message || error?.message || String(error)
          setSaveError(`Backend błąd: ${msg}`)
          return
        }
      }

      // Create new workout via upload endpoint
      if (!currentFile) {
        setSaveError('Brak pliku do wysłania')
        return
      }

      try {
        const uploadedWorkout = await uploadTcxFile(currentFile)
        const workoutId = uploadedWorkout.id

        // Update workout meta
        await updateWorkoutMeta(workoutId, workoutMeta)

        const fresh = await getWorkouts()
        setWorkouts(fresh)
        setNote('')
        setSaveSuccess('Trening zapisany w bazie')
      } catch (error: any) {
        if (error?.response?.status === 409) {
          setSaveSuccess('Ten trening już jest w bazie (duplikat).')
          setSaveError(null)
          await loadWorkouts()
          return
        }
        const msg = error?.response?.data?.message || error?.message || String(error)
        setSaveError(`Backend błąd: ${msg}`)
      }
    } catch (err) {
      const message =
        err instanceof Error ? err.message : 'Błąd zapisu do backendu'
      setSaveError(message)
    } finally {
      setIsSaving(false)
    }
  }

  const loadTrainingFromDb = async (id: string) => {
    try {
      console.log('Loading workout from DB, id =', id)
      setCurrentWorkoutId(id)
      const workout = await getWorkout(id)
      console.log('Workout from API:', workout)

      if (!workout.tcxRaw) {
        console.error('tcxRaw is missing in workout response')
        return
      }

      const s: any =
        typeof workout.summary === 'string'
          ? JSON.parse(workout.summary)
          : workout.summary ?? {}

      const meta: any =
        typeof workout.workoutMeta === 'string'
          ? JSON.parse(workout.workoutMeta)
          : workout.workoutMeta ?? {}

      console.log('Workout planCompliance:', meta.planCompliance)
      console.log('Workout rpe:', meta.rpe)

      setSuggestion(meta.suggestedPlanCompliance ?? null)
      setSuggestionReason(meta.suggestionReason ?? null)
      setPlanCompliance(meta.planCompliance ?? 'unplanned')
      setRpe(typeof meta.rpe === 'number' ? meta.rpe : null)
      setNote(typeof meta.note === 'string' ? meta.note : '')

      const fw = meta.fatigueFlag === true
        ? '⚠️ Możliwe zmęczenie: wysokie RPE przy niskim obciążeniu'
        : null

      setFatigueWarning(fw)

      if (meta.fatigueFlag) {
        console.warn('⚠️ Możliwe zmęczenie: wysokie RPE przy niskim obciążeniu')
      }

      setCurrentFileName(s.fileName ?? null)
      setCurrentWorkoutDate(getWorkoutDate(workout) ?? null)

      loadTcx(workout.tcxRaw, s.fileName ?? undefined)
    } catch (err) {
      console.error('Failed to load workout from DB', err)
    }
  }

  const handleDeleteWorkout = async (id: string, e: React.MouseEvent) => {
    e.stopPropagation()
    try {
      await deleteWorkout(id)
      setWorkouts((prev) => prev.filter((w) => String(w.id) !== id))
    } catch (err) {
      console.error('Failed to delete workout', err)
    }
  }

  // ---------- JSX ----------
  return (
    <div className="bg-slate-950 min-h-screen text-white">
      <div className="mx-auto max-w-6xl px-4 py-10">
        <div className="mb-6 flex items-center justify-between">
          <div className="text-sm text-slate-300">
            {loggedInUser ? (
              <span>
                Zalogowany jako <strong>{loggedInUser}</strong>
              </span>
            ) : (
              <span>Nie zalogowano</span>
            )}
            <div className="text-xs text-slate-400">
              API: {API_BASE_URL} | /health: {backendHealth}
            </div>
          </div>
          <div className="flex items-center gap-2">
            {!loggedInUser && (
              <>
                <input
                  className="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm"
                  placeholder="Login"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                />
                <input
                  type="password"
                  className="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm"
                  placeholder="Hasło"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                />
                <button
                  className="px-3 py-1 rounded bg-emerald-600 text-sm"
                  onClick={handleLogin}
                >
                  Zaloguj
                </button>
              </>
            )}
            {loggedInUser && (
              <button
                className="px-3 py-1 rounded bg-slate-700 text-sm"
                onClick={handleLogout}
              >
                Wyloguj
              </button>
            )}
          </div>
        </div>
        {loggedInUser ? (
          <>
            <header
              className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
              aria-label="TCX workspace"
            >
              <div>
                <div className="flex flex-col gap-1">
                  <div className="text-xs uppercase tracking-[0.25em] text-indigo-300/80">
                    Ten konkretny trening
                  </div>
                  <h1 className="text-3xl font-bold leading-tight text-white sm:text-4xl">
                    TCX Toolkit
                  </h1>
                  <p className="text-sm text-slate-400">
                    Podgląd i edycja pojedynczego TCX (oddzielone od analityki historycznej).
                  </p>
                </div>
                {(currentFileName || currentWorkoutDate) && (
                  <p className="mt-1 text-sm text-slate-400">
                    {currentFileName && (
                      <>
                        Plik: <span className="font-mono">{currentFileName}</span>
                      </>
                    )}
                    {currentWorkoutDate && (
                      <>
                        {' '}
                        • Data treningu:{' '}
                        {new Date(currentWorkoutDate).toLocaleString('pl-PL')}
                      </>
                    )}
                  </p>
                )}
                {fatigueWarning && (
                  <div className="mt-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-200">
                    {fatigueWarning}
                  </div>
                )}
                <p className="mt-2 text-slate-300">
                  Wszystko lokalnie w przeglądarce – wczytaj, przytnij, pobierz.
                </p>
              </div>
              <FilePicker onChange={handleFile} disabled={isParsing} />
            </header>

            <AnalyticsSummary />

            {error && (
              <div className="mt-6 rounded-lg border border-red-500/40 bg-red-900/40 p-4 text-sm text-red-100">
                {error}
              </div>
            )}

            {isParsing && (
              <div className="mt-6 text-sm text-indigo-200">Parsowanie pliku...</div>
            )}

            {!hasActiveWorkout && !isParsing && (
              <div className="mt-10 rounded-xl border border-dashed border-slate-700 bg-slate-900/40 p-8 text-center text-slate-300">
                Wybierz plik TCX, aby zobaczyć metryki i opcje przycinania.
              </div>
            )}

            {hasActiveWorkout && parsed && (
              <div className="mt-8 space-y-8">
                {/* Metryki bazowe */}
                <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                  <MetricCard
                    label="Czas trwania"
                    value={formatSeconds(baseMetrics?.durationSec ?? 0)}
                  />
                  <MetricCard
                    label="Dystans"
                    value={`${((baseMetrics?.distanceM ?? 0) / 1000).toFixed(2)} km`}
                  />
                  <MetricCard
                    label="Średnie tempo"
                    value={formatPace(baseMetrics?.avgPaceSecPerKm ?? null)}
                  />
                  <MetricCard
                    label="Średnie tętno"
                    value={baseMetrics?.avgHr ? `${baseMetrics.avgHr} bpm` : '–'}
                  />
                  <MetricCard
                    label="Maksymalne tętno"
                    value={baseMetrics?.maxHr ? `${baseMetrics.maxHr} bpm` : '–'}
                  />
                </section>

                {/* Sekcja akcji / zapis */}
                <section className="rounded-2xl bg-slate-900/60 p-6 shadow-lg ring-1 ring-white/5">
                  <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <p className="text-xs uppercase tracking-[0.2em] text-indigo-300/80">
                        Akcja
                      </p>
                      <h2 className="text-xl font-semibold text-white">
                        Zapisywanie / podgląd
                      </h2>
                      <p className="text-sm text-slate-300">
                        Wybierz, czy tylko podglądasz, czy przygotowujesz dane do
                        zapisu.
                      </p>
                    </div>
                  </div>

                  <div className="mt-5 grid gap-4 md:grid-cols-2">
                    <div className="space-y-2">
                      <label className="text-sm text-slate-300">Rodzaj</label>
                      <select
                        value={kind}
                        onChange={(e) => setKind(e.target.value as WorkoutKind)}
                        className="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
                      >
                        <option value="training">Trening</option>
                        <option value="race">Zawody</option>
                      </select>
                    </div>

                    <div className="space-y-2">
                      {suggestion && suggestion !== planCompliance && (
                        <div className="mb-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-200">
                          <div className="mb-1">
                            {suggestion === 'unplanned' && 'Ten trening wygląda jak spontaniczny easy / skrócony / regeneracyjny — zmienić zgodność z planem?'}
                            {suggestion === 'modified' && 'Ten trening wygląda jak zmodyfikowany (wysokie RPE przy niskim obciążeniu) — zmienić zgodność z planem?'}
                          </div>
                          {suggestionReason && (
                            <div className="mt-1 text-xs text-amber-200/80">
                              Powód:{" "}
                              {suggestionReason === 'rpe_low_easy'
                                ? 'niskie RPE — wygląda na easy/regeneracyjny'
                                : suggestionReason === 'rpe_high_low_load'
                                ? 'wysokie RPE przy niskim obciążeniu'
                                : suggestionReason}
                            </div>
                          )}
                          <button
                            type="button"
                            onClick={() => setPlanCompliance(suggestion)}
                            className="text-xs underline hover:text-amber-100"
                          >
                            Zastosuj sugestię
                          </button>
                        </div>
                      )}
                      <label className="text-sm text-slate-300">Zgodność z planem</label>
                      <select
                        value={planCompliance}
                        onChange={(e) => setPlanCompliance(e.target.value as 'planned' | 'modified' | 'unplanned')}
                        className="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
                      >
                        <option value="planned">Zgodny z planem</option>
                        <option value="modified">Zmodyfikowany</option>
                        <option value="unplanned">Spontaniczny</option>
                      </select>
                    </div>

                    <div className="space-y-2">
                      <label className="text-sm text-slate-300">RPE (1-10)</label>
                      <select
                        value={rpe ?? ''}
                        onChange={(e) => setRpe(e.target.value === '' ? null : Number(e.target.value))}
                        className="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
                      >
                        <option value="">Nie wybrano</option>
                        {Array.from({ length: 10 }, (_, i) => i + 1).map((num) => (
                          <option key={num} value={num}>
                            {num}
                          </option>
                        ))}
                      </select>
                    </div>

                    {kind === 'race' && (
                      <div className="space-y-2">
                        <label className="text-sm text-slate-300">Nazwa biegu</label>
                        <input
                          type="text"
                          value={raceMeta.name}
                          onChange={(e) =>
                            setRaceMeta((prev) => ({ ...prev, name: e.target.value }))
                          }
                          placeholder="np. Maraton Warszawski"
                          className="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
                        />
                      </div>
                    )}

                    {kind === 'race' && (
                      <div className="space-y-2">
                        <label className="text-sm text-slate-300">Dystans</label>
                        <select
                          value={raceMeta.distance}
                          onChange={(e) =>
                            setRaceMeta((prev) => ({
                              ...prev,
                              distance: e.target.value as RaceMeta['distance'],
                            }))
                          }
                          className="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
                        >
                          <option value="5 km">5 km</option>
                          <option value="10 km">10 km</option>
                          <option value="21.1 km">21.1 km</option>
                          <option value="42.2 km">42.2 km</option>
                          <option value="Inny">Inny</option>
                        </select>
                        {raceMeta.distance === 'Inny' && (
                          <input
                            type="text"
                            value={raceMeta.customDistance ?? ''}
                            onChange={(e) =>
                              setRaceMeta((prev) => ({
                                ...prev,
                                customDistance: e.target.value,
                              }))
                            }
                            placeholder="Podaj dystans (np. 15 km)"
                            className="mt-2 w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
                          />
                        )}
                      </div>
                    )}

                    {kind === 'race' && (
                      <div className="space-y-2">
                        <label className="text-sm text-slate-300">Priorytet</label>
                        <select
                          value={raceMeta.priority}
                          onChange={(e) =>
                            setRaceMeta((prev) => ({
                              ...prev,
                              priority: e.target.value as RaceMeta['priority'],
                            }))
                          }
                          className="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
                        >
                          <option value="A">A</option>
                          <option value="B">B</option>
                          <option value="C">C</option>
                        </select>
                      </div>
                    )}
                  </div>

                  {planCompliance !== 'planned' && (
                    <div className="mt-4">
                      <label className="block text-sm text-slate-300 mb-2">
                        Dlaczego trening był zmodyfikowany / spontaniczny?
                        <span className="text-slate-500"> (max 300 znaków)</span>
                      </label>
                      <textarea
                        value={note}
                        onChange={(e) => setNote(e.target.value.slice(0, 300))}
                        rows={3}
                        className="w-full rounded-md border border-slate-700 bg-slate-900/60 px-3 py-2 text-slate-100 outline-none focus:border-indigo-400"
                        placeholder="Np. brak czasu / ciężkie nogi / zmiana pogody / brak mocy..."
                      />
                    </div>
                  )}

                  <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
                    <div className="text-xs text-slate-400">User: {loggedInUser}</div>
                    <div className="flex items-center gap-3">
                      <button
                        type="button"
                        onClick={handleSaveToBackend}
                        disabled={isSaving || (currentWorkoutId ? !summary : !currentFile)}
                    className={`rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-lg transition ${
                      isSaving || (currentWorkoutId ? !summary : !currentFile)
                        ? 'bg-slate-600 cursor-not-allowed opacity-60'
                        : 'bg-emerald-500 shadow-emerald-500/30 hover:bg-emerald-400'
                    }`}
                      >
                        {isSaving ? 'Zapisywanie...' : 'Zapisz do bazy'}
                      </button>
                    </div>
                  </div>

                  {(saveError || saveSuccess) && (
                    <div className="mt-3 space-y-2 text-sm">
                      {saveError && (
                        <div className="rounded-md border border-red-500/40 bg-red-900/40 px-3 py-2 text-red-100">
                          {saveError}
                        </div>
                      )}
                      {saveSuccess && (
                        <div className="rounded-md border border-emerald-500/30 bg-emerald-900/30 px-3 py-2 text-emerald-100">
                          {saveSuccess}
                        </div>
                      )}
                    </div>
                  )}
                </section>

              </div>
            )}

            {loggedInUser && (
              <WorkoutsList
                workouts={workouts}
                loggedInUser={loggedInUser}
                onLoadWorkout={loadTrainingFromDb}
                onDeleteWorkout={handleDeleteWorkout}
              />
            )}
          </>
        ) : (
          <div className="mt-10 rounded-xl border border-dashed border-slate-700 bg-slate-900/40 p-8 text-center text-slate-300">
            Zaloguj się, aby korzystać z edytora i listy treningów.
          </div>
        )}
      </div>
    </div>
  )
}

export default App
