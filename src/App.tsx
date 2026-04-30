import { useEffect, useMemo, useState, useCallback, useRef } from 'react'
import type { ChangeEvent } from 'react'
import {
  AUTH_LOGOUT_EVENT,
  AUTH_LOGOUT_REASON_SESSION_EXPIRED,
  clearSessionHeaders,
  getStoredSessionToken,
  getStoredUsername,
  hasStoredSession,
} from './api/client'
import { computeMetrics } from './utils/metrics'
import { parseTcx } from './utils/tcxParser'
import type {
  Metrics,
  ParsedTcx,
  RaceMeta,
  WorkoutKind,
} from './types'
import {
  getWorkouts,
  getWorkout,
  deleteWorkout,
  deleteAllWorkouts,
  uploadTcxFile,
  updateWorkoutMeta,
  getWorkoutFeedback,
  generateWorkoutFeedback,
  type WorkoutListItem,
  type WorkoutFeedback,
  type ManualCheckInResponse,
  getWorkoutDate,
} from './api/workouts'
import { forgotPassword, login, register, resetPassword } from './api/auth'
import { getMyProfile, type UserProfile } from './api/profile'
import WorkoutsList from './components/WorkoutsList'
import AnalyticsSummary from './components/AnalyticsSummary'
import WeeklyPlanSection from './components/WeeklyPlanSection'
import AiPlanSection from './components/AiPlanSection'
import Onboarding from './components/Onboarding'
import GarminSection from './components/GarminSection'
import OnboardingSummaryCard from './components/OnboardingSummaryCard'
import WorkoutFeedbackPanel from './components/WorkoutFeedbackPanel'
import ProfileEditSection from './components/ProfileEditSection'
import IntegrationsSettingsSection from './components/IntegrationsSettingsSection'

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

type AppTabId = 'dashboard' | 'plan' | 'history' | 'profile' | 'settings'
type LogoutReason = 'manual' | 'session-expired'
type AuthMode = 'login' | 'register' | 'forgot' | 'reset'

const APP_TABS: Array<{ id: AppTabId; label: string }> = [
  { id: 'dashboard', label: 'Dashboard' },
  { id: 'plan', label: 'Plan' },
  { id: 'history', label: 'Historia' },
  { id: 'profile', label: 'Profil' },
  { id: 'settings', label: 'Ustawienia' },
]

const getOnboardingSkipped = (profile: UserProfile | null): boolean => {
  if (!profile?.constraints) return false
  try {
    const parsed = JSON.parse(profile.constraints)
    return parsed?.onboarding?.skipped === true
  } catch {
    return false
  }
}

const OnboardingReturnCta = ({ onClick }: { onClick: () => void }) => (
  <section className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-5 text-amber-100">
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p className="text-xs uppercase tracking-[0.22em] text-amber-200/80">
          Profil wymaga uzupełnienia
        </p>
        <h2 className="mt-1 text-lg font-semibold text-white">Dokończ onboarding</h2>
      </div>
      <button
        type="button"
        onClick={onClick}
        className="min-h-11 rounded-lg bg-amber-400 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-amber-300"
      >
        Uzupełnij dane
      </button>
    </div>
  </section>
)

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
  const [username, setUsername] = useState<string>(() => {
    return getStoredUsername() || ''
  })
  const [password, setPassword] = useState<string>('')
  const [confirmPassword, setConfirmPassword] = useState<string>('')
  const [email, setEmail] = useState<string>('')
  const [authMode, setAuthMode] = useState<AuthMode>('login')
  const [authError, setAuthError] = useState<string | null>(null)
  const [authInfo, setAuthInfo] = useState<string | null>(null)
  const [isAuthSubmitting, setIsAuthSubmitting] = useState(false)
  const [resetToken, setResetToken] = useState('')
  const usernameInputRef = useRef<HTMLInputElement>(null)
  const passwordInputRef = useRef<HTMLInputElement>(null)
  const [loggedInUser, setLoggedInUser] = useState<string | null>(() => {
    return getStoredUsername()
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
  const [isSaving, setIsSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [saveSuccess, setSaveSuccess] = useState<string | null>(null)
  const [workoutFeedback, setWorkoutFeedback] = useState<WorkoutFeedback | null>(null)
  const [isFeedbackLoading, setIsFeedbackLoading] = useState(false)
  const [feedbackError, setFeedbackError] = useState<string | null>(null)
  const [workouts, setWorkouts] = useState<WorkoutListItem[]>([])
  const [planCompliance, setPlanCompliance] = useState<'planned' | 'modified' | 'unplanned'>('unplanned')
  const [rpe, setRpe] = useState<number | null>(null)
  const [fatigueWarning, setFatigueWarning] = useState<string | null>(null)
  const [note, setNote] = useState('')
  const [suggestion, setSuggestion] = useState<'planned' | 'modified' | 'unplanned' | null>(null)
  const [suggestionReason, setSuggestionReason] = useState<string | null>(null)
  const [onboardingCompleted, setOnboardingCompleted] = useState<boolean | null>(null)
  const [profile, setProfile] = useState<UserProfile | null>(null)
  const [onboardingNeedsAttention, setOnboardingNeedsAttention] = useState(false)
  const [isOnboardingOpen, setIsOnboardingOpen] = useState(false)
  const [authRefreshToken, setAuthRefreshToken] = useState(0)
  const [planRefreshToken, setPlanRefreshToken] = useState(0)
  const [activeTab, setActiveTab] = useState<AppTabId>('dashboard')
  const [integrationNotice, setIntegrationNotice] = useState<{ type: 'ok' | 'err'; text: string } | null>(null)

  // Auth headers are injected by the axios interceptor in `src/api/client.ts`

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const tokenFromUrl = params.get('resetToken') ?? params.get('token')
    const identifierFromUrl =
      params.get('email') ?? params.get('identifier') ?? params.get('username')

    if (tokenFromUrl) {
      clearSessionHeaders()
      setLoggedInUser(null)
      setAuthMode('reset')
      setResetToken(tokenFromUrl)
      setAuthError(null)
      setAuthInfo('Ustaw nowe haslo dla konta.')
      if (identifierFromUrl) {
        setUsername(identifierFromUrl)
      }
    }

    const integration = params.get('integration')
    const integrationStatus = params.get('status')
    if (integration === 'strava') {
      setActiveTab('settings')
      setIntegrationNotice(
        integrationStatus === 'connected'
          ? {
              type: 'ok',
              text: 'Strava połączona. Możesz teraz uruchomić synchronizację historii.',
            }
          : {
              type: 'err',
              text: `Nie udało się połączyć Stravy: ${params.get('error') || 'spróbuj ponownie'}.`,
            },
      )
      setAuthRefreshToken((prev) => prev + 1)
      window.history.replaceState({}, '', window.location.pathname)
    }
  }, [])

  const baseMetrics = useMemo(
    () => (parsed ? computeMetrics(parsed.trackpoints) : null),
    [parsed],
  )
  const feedbackMetrics: Metrics | null = baseMetrics

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
      if (name) setCurrentFileName(name)
      setStartIndex(0)
      setEndIndex(result.trackpoints.length - 1)
    } catch (err) {
      const message =
        err instanceof Error ? err.message : 'Nie udało się sparsować pliku.'
      setError(message)
      setParsed(null)
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
        setWorkoutFeedback(null)
        setFeedbackError(null)
        setFatigueWarning(null)
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

  const loadWorkoutFeedback = useCallback(
    async (workoutId: number | string, options: { generateIfMissing?: boolean } = {}) => {
      setIsFeedbackLoading(true)
      setFeedbackError(null)
      try {
        const feedback = options.generateIfMissing
          ? await generateWorkoutFeedback(workoutId)
          : await getWorkoutFeedback(workoutId)
        setWorkoutFeedback(feedback)
        return feedback
      } catch (err: any) {
        if (err?.response?.status === 404 && !options.generateIfMissing) {
          setWorkoutFeedback(null)
          return null
        }

        const message =
          err?.response?.data?.message ||
          err?.message ||
          'Nie udało się pobrać feedbacku dla treningu'
        setFeedbackError(message)
        return null
      } finally {
        setIsFeedbackLoading(false)
      }
    },
    [],
  )

  const refreshOnboardingStatus = useCallback(async () => {
    if (!hasStoredSession()) {
      setOnboardingCompleted(null)
      setProfile(null)
      setOnboardingNeedsAttention(false)
      return
    }
    try {
      const profile = await getMyProfile()
      console.log('PROFILE RESPONSE:', profile)
      console.log('onboardingCompleted:', profile.onboardingCompleted)
      setProfile(profile)
      setOnboardingCompleted(Boolean(profile.onboardingCompleted))
      setOnboardingNeedsAttention(!profile.onboardingCompleted || getOnboardingSkipped(profile))
    } catch (err) {
      console.warn('Nie udało się pobrać profilu', err)
      setOnboardingCompleted(false)
    }
  }, [])

  const refreshRollingPlan = useCallback(() => {
    setPlanRefreshToken((prev) => prev + 1)
  }, [])

  useEffect(() => {
    if (!hasStoredSession()) {
      setWorkouts([])
      return
    }

    loadWorkouts()
  }, [loggedInUser, loadWorkouts])

  useEffect(() => {
    if (!loggedInUser) {
      setOnboardingCompleted(null)
      return
    }
    refreshOnboardingStatus()
  }, [loggedInUser, refreshOnboardingStatus])

  const handleLogout = useCallback((reason: LogoutReason = 'manual') => {
    setLoggedInUser(null)
    clearSessionHeaders()
    setPassword('')
    setConfirmPassword('')
    setEmail('')
    setAuthError(
      reason === AUTH_LOGOUT_REASON_SESSION_EXPIRED
        ? 'Sesja wygasla. Zaloguj sie ponownie.'
        : null,
    )
    setAuthInfo(null)
    setAuthMode('login')
    setWorkouts([])
    setCurrentFileName(null)
    setCurrentWorkoutDate(null)
    setParsed(null)
    setStartIndex(0)
    setEndIndex(0)
    setSaveError(null)
    setSaveSuccess(null)
    setWorkoutFeedback(null)
    setFeedbackError(null)
    setIsFeedbackLoading(false)
    setOnboardingCompleted(null)
    setProfile(null)
    setOnboardingNeedsAttention(false)
    setIsOnboardingOpen(false)
    setActiveTab('dashboard')
    setAuthRefreshToken((prev) => prev + 1)
  }, [])

  useEffect(() => {
    const onUnauthorized = (event: Event) => {
      const reason = (event as CustomEvent<{ reason?: LogoutReason }>).detail?.reason
      handleLogout(reason === AUTH_LOGOUT_REASON_SESSION_EXPIRED ? 'session-expired' : 'manual')
    }
    window.addEventListener(AUTH_LOGOUT_EVENT, onUnauthorized)
    return () => window.removeEventListener(AUTH_LOGOUT_EVENT, onUnauthorized)
  }, [handleLogout])

  const handleLogin = async () => {
    setIsAuthSubmitting(true)
    setAuthError(null)
    setAuthInfo(null)
    try {
      const loginUsername = usernameInputRef.current?.value ?? username
      const loginPassword = passwordInputRef.current?.value ?? password
      const result = await login(loginUsername.trim(), loginPassword)
      // setSessionHeaders + localStorage już ustawione wewnątrz login() → auth.ts

      setLoggedInUser(result.username)
      setEmail('')
      setPassword('')
      setConfirmPassword('')
      setResetToken('')
      setOnboardingCompleted(null)
      setAuthRefreshToken((prev) => prev + 1)
      // refreshOnboardingStatus i loadWorkouts wywołują useEffect([loggedInUser]) — nie dublujemy
    } catch (err) {
      console.error('Login failed', err)
      setAuthError('Niepoprawny login lub haslo')
    } finally {
      setIsAuthSubmitting(false)
    }
  }

  const handleRegister = async () => {
    const registerUsername = (usernameInputRef.current?.value ?? username).trim()
    const registerPassword = passwordInputRef.current?.value ?? password
    const registerEmail = email.trim()

    setAuthError(null)
    setAuthInfo(null)

    if (registerUsername.length < 3) {
      setAuthError('Login musi miec co najmniej 3 znaki')
      return
    }

    if (!registerEmail || !registerEmail.includes('@')) {
      setAuthError('Podaj poprawny email do resetu hasla')
      return
    }

    if (registerPassword.length < 8) {
      setAuthError('Haslo musi miec co najmniej 8 znakow')
      return
    }

    if (registerPassword !== confirmPassword) {
      setAuthError('Hasla nie sa takie same')
      return
    }

    setIsAuthSubmitting(true)
    try {
      const result = await register(registerUsername, registerPassword, registerEmail)
      setLoggedInUser(result.username)
      setEmail('')
      setPassword('')
      setConfirmPassword('')
      setResetToken('')
      setWorkouts([])
      setProfile(null)
      setOnboardingCompleted(false)
      setOnboardingNeedsAttention(false)
      setIsOnboardingOpen(false)
      setAuthRefreshToken((prev) => prev + 1)
      setActiveTab('dashboard')
    } catch (err: any) {
      console.error('Register failed', err)
      const status = err?.response?.status
      setAuthError(
        status === 422
          ? 'Konto o takim loginie albo emailu juz istnieje'
          : 'Nie udalo sie zalozyc konta',
      )
    } finally {
      setIsAuthSubmitting(false)
    }
  }

  const handleForgotPassword = async () => {
    const identifier = (usernameInputRef.current?.value ?? username).trim()

    setAuthError(null)
    setAuthInfo(null)

    if (!identifier) {
      setAuthError('Podaj login albo email')
      return
    }

    setIsAuthSubmitting(true)
    try {
      await forgotPassword(identifier)
      setAuthInfo('Jesli konto istnieje, wyslalismy link resetu hasla.')
    } catch (err) {
      console.error('Forgot password failed', err)
      setAuthError('Nie udalo sie wyslac linku resetu')
    } finally {
      setIsAuthSubmitting(false)
    }
  }

  const handleResetPassword = async () => {
    const identifier = (usernameInputRef.current?.value ?? username).trim()
    const newPassword = passwordInputRef.current?.value ?? password

    setAuthError(null)
    setAuthInfo(null)

    if (!identifier) {
      setAuthError('Podaj login albo email')
      return
    }

    if (!resetToken.trim()) {
      setAuthError('Brakuje tokena resetu')
      return
    }

    if (newPassword.length < 8) {
      setAuthError('Nowe haslo musi miec co najmniej 8 znakow')
      return
    }

    if (newPassword !== confirmPassword) {
      setAuthError('Hasla nie sa takie same')
      return
    }

    setIsAuthSubmitting(true)
    try {
      await resetPassword(identifier, resetToken.trim(), newPassword)
      setPassword('')
      setConfirmPassword('')
      setResetToken('')
      setAuthMode('login')
      setAuthInfo('Haslo zostalo zmienione. Mozesz sie zalogowac.')
      window.history.replaceState({}, '', window.location.pathname)
    } catch (err) {
      console.error('Reset password failed', err)
      setAuthError('Token resetu jest niepoprawny albo wygasl')
    } finally {
      setIsAuthSubmitting(false)
    }
  }

  const handleOnboardingCompleted = useCallback((result?: { skipped?: boolean }) => {
    setOnboardingCompleted(true)
    setOnboardingNeedsAttention(result?.skipped === true)
    setIsOnboardingOpen(false)
    setAuthRefreshToken((prev) => prev + 1)
    void loadWorkouts()
  }, [loadWorkouts])

  const handleSaveToBackend = async () => {
    if (!getStoredSessionToken() || !getStoredUsername()) {
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
          refreshRollingPlan()
          await loadWorkoutFeedback(currentWorkoutId, { generateIfMissing: true })
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
        setCurrentWorkoutId(String(workoutId))
        setCurrentWorkoutDate(summary?.startTimeIso ?? uploadedWorkout.createdAt ?? null)
        setNote('')
        setSaveSuccess('Trening zapisany w bazie')
        refreshRollingPlan()
        await loadWorkoutFeedback(workoutId, { generateIfMissing: true })
      } catch (error: any) {
        if (error?.response?.status === 409) {
          setSaveSuccess('Ten trening już jest w bazie (duplikat).')
          setSaveError(null)
          await loadWorkouts()
          refreshRollingPlan()
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
      setCurrentFile(null)
      setWorkoutFeedback(null)
      setFeedbackError(null)
      const workout = await getWorkout(id)
      console.log('Workout from API:', workout)

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

      if (workout.tcxRaw) {
        await loadTcx(workout.tcxRaw, s.fileName ?? undefined)
      } else {
        setParsed(null)
        setStartIndex(0)
        setEndIndex(0)
        setError(null)
      }

      await loadWorkoutFeedback(id)
    } catch (err) {
      console.error('Failed to load workout from DB', err)
    }
  }

  const handleDeleteWorkout = async (id: string, e: React.MouseEvent) => {
    e.stopPropagation()
    try {
      await deleteWorkout(id)
      setWorkouts((prev) => prev.filter((w) => String(w.id) !== id))
      if (currentWorkoutId === id) {
        setCurrentWorkoutId(null)
        setCurrentWorkoutDate(null)
        setCurrentFileName(null)
        setWorkoutFeedback(null)
        setFeedbackError(null)
        setParsed(null)
      }
    } catch (err) {
      console.error('Failed to delete workout', err)
    }
  }

  const handleDeleteAllWorkouts = async () => {
    try {
      await deleteAllWorkouts()
      setWorkouts([])
      setCurrentWorkoutId(null)
      setCurrentWorkoutDate(null)
      setCurrentFileName(null)
      setWorkoutFeedback(null)
      setFeedbackError(null)
      setParsed(null)
    } catch (err) {
      console.error('Failed to delete all workouts', err)
    }
  }

  const handleGarminSyncComplete = useCallback(async () => {
    await loadWorkouts()
    refreshRollingPlan()
  }, [loadWorkouts, refreshRollingPlan])

  const handleManualCheckInSaved = useCallback(
    async (result: ManualCheckInResponse) => {
      await loadWorkouts()
      refreshRollingPlan()

      const { checkIn } = result
      setCurrentFile(null)
      setCurrentFileName(null)
      setCurrentWorkoutDate(checkIn.plannedSessionDate ?? null)
      setParsed(null)
      setStartIndex(0)
      setEndIndex(0)
      setError(null)
      setFeedbackError(null)
      setFatigueWarning(
        checkIn.painFlag
          ? 'Zgłoszono ból lub kontuzję w manualnym check-inie. Plan powinien być ostrożniejszy po odświeżeniu.'
          : null,
      )
      setPlanCompliance(checkIn.status === 'modified' ? 'modified' : 'planned')
      setRpe(typeof checkIn.rpe === 'number' ? checkIn.rpe : null)
      setNote(checkIn.note ?? '')

      if (checkIn.workoutId) {
        const workoutId = String(checkIn.workoutId)
        setCurrentWorkoutId(workoutId)
        await loadWorkoutFeedback(workoutId, { generateIfMissing: true })
        return
      }

      setCurrentWorkoutId(null)
      setWorkoutFeedback(null)
    },
    [loadWorkoutFeedback, loadWorkouts, refreshRollingPlan],
  )

  const handleGenerateFeedback = useCallback(() => {
    if (!currentWorkoutId) return
    void loadWorkoutFeedback(currentWorkoutId, { generateIfMissing: true })
  }, [currentWorkoutId, loadWorkoutFeedback])

  const handleRefreshFeedback = useCallback(() => {
    if (!currentWorkoutId) return
    void loadWorkoutFeedback(currentWorkoutId)
  }, [currentWorkoutId, loadWorkoutFeedback])

  const showPasswordInput = authMode === 'login' || authMode === 'register' || authMode === 'reset'
  const showConfirmPasswordInput = authMode === 'register' || authMode === 'reset'
  const usernamePlaceholder =
    authMode === 'forgot' || authMode === 'reset' ? 'Login lub email' : 'Login'
  const passwordPlaceholder = authMode === 'reset' ? 'Nowe haslo' : 'Haslo'
  const passwordAutocomplete = authMode === 'reset' || authMode === 'register'
    ? 'new-password'
    : 'current-password'
  const authSubmitLabel =
    authMode === 'register'
      ? 'Utworz konto'
      : authMode === 'forgot'
      ? 'Wyslij link'
      : authMode === 'reset'
      ? 'Zmien haslo'
      : 'Zaloguj'
  const handleAuthSubmit =
    authMode === 'register'
      ? handleRegister
      : authMode === 'forgot'
      ? handleForgotPassword
      : authMode === 'reset'
      ? handleResetPassword
      : handleLogin
  const rollingPlanRefreshToken = authRefreshToken + planRefreshToken

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
              API: {API_BASE_URL}
            </div>
          </div>
          <div className="flex flex-wrap items-center justify-end gap-2">
            {!loggedInUser && (
              <>
                <button
                  type="button"
                  className={`rounded px-3 py-1 text-sm ${
                    authMode === 'login'
                      ? 'bg-slate-700 text-white'
                      : 'bg-transparent text-slate-300 hover:bg-slate-800'
                  }`}
                  onClick={() => {
                    setAuthMode('login')
                    setAuthError(null)
                    setAuthInfo(null)
                    setResetToken('')
                  }}
                >
                  Logowanie
                </button>
                <button
                  type="button"
                  className={`rounded px-3 py-1 text-sm ${
                    authMode === 'register'
                      ? 'bg-slate-700 text-white'
                      : 'bg-transparent text-slate-300 hover:bg-slate-800'
                  }`}
                  onClick={() => {
                    setAuthMode('register')
                    setAuthError(null)
                    setAuthInfo(null)
                    setResetToken('')
                  }}
                >
                  Załóż konto
                </button>
                <button
                  type="button"
                  className={`rounded px-3 py-1 text-sm ${
                    authMode === 'forgot' || authMode === 'reset'
                      ? 'bg-slate-700 text-white'
                      : 'bg-transparent text-slate-300 hover:bg-slate-800'
                  }`}
                  onClick={() => {
                    setAuthMode('forgot')
                    setAuthError(null)
                    setAuthInfo(null)
                    setPassword('')
                    setConfirmPassword('')
                    setResetToken('')
                  }}
                >
                  Nie pamietam hasla
                </button>
                <input
                  ref={usernameInputRef}
                  className="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm"
                  placeholder={usernamePlaceholder}
                  autoComplete="username"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                />
                {authMode === 'register' && (
                  <input
                    type="email"
                    className="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm"
                    placeholder="Email"
                    autoComplete="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                  />
                )}
                {authMode === 'reset' && (
                  <input
                    type="text"
                    className="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm"
                    placeholder="Token resetu"
                    autoComplete="one-time-code"
                    value={resetToken}
                    onChange={(e) => setResetToken(e.target.value)}
                  />
                )}
                {showPasswordInput && (
                  <input
                    ref={passwordInputRef}
                    type="password"
                    className="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm"
                    placeholder={passwordPlaceholder}
                    autoComplete={passwordAutocomplete}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                  />
                )}
                {showConfirmPasswordInput && (
                  <input
                    type="password"
                    className="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm"
                    placeholder="Powtorz haslo"
                    autoComplete="new-password"
                    value={confirmPassword}
                    onChange={(e) => setConfirmPassword(e.target.value)}
                  />
                )}
                <button
                  className="px-3 py-1 rounded bg-emerald-600 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                  onClick={handleAuthSubmit}
                  disabled={isAuthSubmitting}
                >
                  {isAuthSubmitting
                    ? 'Chwila...'
                    : authSubmitLabel}
                </button>
                {authInfo && (
                  <div className="basis-full text-right text-xs text-emerald-300">
                    {authInfo}
                  </div>
                )}
                {authError && (
                  <div className="basis-full text-right text-xs text-red-300">
                    {authError}
                  </div>
                )}
              </>
            )}
            {loggedInUser && (
              <button
                className="px-3 py-1 rounded bg-slate-700 text-sm"
                onClick={() => handleLogout()}
              >
                Wyloguj
              </button>
            )}
          </div>
        </div>
        {loggedInUser ? (
          <>
            {isOnboardingOpen || (onboardingCompleted === false && workouts.length === 0) ? (
              <Onboarding
                onCompleted={handleOnboardingCompleted}
                initialProfile={profile}
              />
            ) : onboardingCompleted === null ? (
              <div className="mt-10 rounded-xl border border-dashed border-slate-700 bg-slate-900/40 p-8 text-center text-slate-300">
                Sprawdzanie statusu onboardingu...
              </div>
            ) : (
              <>
                <nav
                  className="mb-8 flex gap-2 overflow-x-auto rounded-xl border border-slate-800 bg-slate-900/70 p-1"
                  aria-label="Główna nawigacja"
                >
                  {APP_TABS.map((tab) => {
                    const isActive = activeTab === tab.id
                    return (
                      <button
                        key={tab.id}
                        type="button"
                        onClick={() => setActiveTab(tab.id)}
                        className={`min-h-11 shrink-0 rounded-lg px-4 py-2 text-sm font-semibold transition ${
                          isActive
                            ? 'bg-indigo-500 text-white shadow-lg shadow-indigo-950/30'
                            : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                        }`}
                        aria-current={isActive ? 'page' : undefined}
                      >
                        {tab.label}
                      </button>
                    )
                  })}
                </nav>

                {integrationNotice && (
                  <div
                    className={`mb-6 rounded-lg px-4 py-3 text-sm ${
                      integrationNotice.type === 'ok'
                        ? 'bg-emerald-900/40 text-emerald-200'
                        : 'bg-rose-900/40 text-rose-200'
                    }`}
                  >
                    {integrationNotice.text}
                  </div>
                )}

                {activeTab === 'dashboard' && (
                  <>
            <header
              className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
              aria-label="MarcinCoach workout workspace"
            >
              <div>
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                  <div className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-slate-800 bg-slate-950 shadow-lg shadow-black/20 sm:h-24 sm:w-24">
                    <img
                      src="/marcincoach-logo.png"
                      alt="MarcinCoach logo"
                      className="h-full w-full object-cover"
                    />
                  </div>
                  <div className="flex flex-col gap-1">
                    <div className="text-xs uppercase tracking-[0.25em] text-indigo-300/80">
                      Ten konkretny trening
                    </div>
                    <h1 className="text-3xl font-bold leading-tight text-white sm:text-4xl">
                      MarcinCoach
                    </h1>
                    <p className="text-sm text-slate-400">
                      Treningi, plan tygodniowy i sygnały adaptacji w jednym miejscu.
                    </p>
                  </div>
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
                  Wgraj trening, sprawdź historię i odśwież plan na podstawie aktualnych danych.
                </p>
              </div>
              <FilePicker onChange={handleFile} disabled={isParsing} />
            </header>

            {onboardingNeedsAttention && (
              <div className="mt-8">
                <OnboardingReturnCta onClick={() => setIsOnboardingOpen(true)} />
              </div>
            )}

            <OnboardingSummaryCard refreshToken={authRefreshToken} />

            <AnalyticsSummary refreshToken={authRefreshToken} />

            <GarminSection
              refreshToken={authRefreshToken}
              onSyncComplete={handleGarminSyncComplete}
            />

            <WeeklyPlanSection
              refreshToken={rollingPlanRefreshToken}
              onManualCheckInSaved={handleManualCheckInSaved}
            />

            <AiPlanSection refreshToken={authRefreshToken} />

            {error && (
              <div className="mt-6 rounded-lg border border-red-500/40 bg-red-900/40 p-4 text-sm text-red-100">
                {error}
              </div>
            )}

            {isParsing && (
              <div className="mt-6 text-sm text-indigo-200">Parsowanie pliku...</div>
            )}

            {!hasActiveWorkout && !isParsing && !currentWorkoutId && (
              <div className="mt-10 rounded-xl border border-dashed border-slate-700 bg-slate-900/40 p-8 text-center text-slate-300">
                Wybierz plik TCX, aby zobaczyć metryki i opcje przycinania.
              </div>
            )}

            {!hasActiveWorkout && !isParsing && currentWorkoutId && (
              <div className="mt-10 rounded-xl border border-dashed border-slate-700 bg-slate-900/40 p-8 text-center text-slate-300">
                Ten trening nie ma zapisanego pliku TCX do podglądu, ale feedback możesz odczytać poniżej.
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

            {currentWorkoutId && (
              <WorkoutFeedbackPanel
                feedback={workoutFeedback}
                metrics={feedbackMetrics}
                isLoading={isFeedbackLoading}
                error={feedbackError}
                canGenerate={Boolean(currentWorkoutId)}
                onGenerate={handleGenerateFeedback}
                onRefresh={handleRefreshFeedback}
              />
            )}

            {loggedInUser && (
              <WorkoutsList
                workouts={workouts}
                loggedInUser={loggedInUser}
                onLoadWorkout={loadTrainingFromDb}
                onDeleteWorkout={handleDeleteWorkout}
                onDeleteAllWorkouts={handleDeleteAllWorkouts}
              />
            )}
                  </>
                )}

                {activeTab === 'plan' && (
                  <div className="space-y-8">
                    <WeeklyPlanSection
                      refreshToken={rollingPlanRefreshToken}
                      onManualCheckInSaved={handleManualCheckInSaved}
                    />
                    <AiPlanSection refreshToken={authRefreshToken} />
                    {currentWorkoutId && (
                      <WorkoutFeedbackPanel
                        feedback={workoutFeedback}
                        metrics={feedbackMetrics}
                        isLoading={isFeedbackLoading}
                        error={feedbackError}
                        canGenerate={Boolean(currentWorkoutId)}
                        onGenerate={handleGenerateFeedback}
                        onRefresh={handleRefreshFeedback}
                      />
                    )}
                  </div>
                )}

                {activeTab === 'history' && (
                  <div className="space-y-8">
                    <header className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                      <div>
                        <p className="text-xs uppercase tracking-[0.25em] text-indigo-300/80">
                          Historia
                        </p>
                        <h1 className="text-3xl font-bold leading-tight text-white sm:text-4xl">
                          Treningi
                        </h1>
                      </div>
                      <FilePicker
                        onChange={(file) => {
                          setActiveTab('dashboard')
                          void handleFile(file)
                        }}
                        disabled={isParsing}
                      />
                    </header>
                    <WorkoutsList
                      workouts={workouts}
                      loggedInUser={loggedInUser}
                      onLoadWorkout={(id) => {
                        setActiveTab('dashboard')
                        void loadTrainingFromDb(id)
                      }}
                      onDeleteWorkout={handleDeleteWorkout}
                      onDeleteAllWorkouts={handleDeleteAllWorkouts}
                    />
                  </div>
                )}

                {activeTab === 'profile' && (
                  <div className="space-y-8">
                    <header>
                      <p className="text-xs uppercase tracking-[0.25em] text-indigo-300/80">
                        Profil
                      </p>
                      <h1 className="text-3xl font-bold leading-tight text-white sm:text-4xl">
                        Dane biegacza
                      </h1>
                    </header>
                    {onboardingNeedsAttention && (
                      <OnboardingReturnCta onClick={() => setIsOnboardingOpen(true)} />
                    )}
                    <ProfileEditSection
                      profile={profile}
                      onProfileUpdated={(updated) => {
                        setProfile(updated)
                        setOnboardingCompleted(Boolean(updated.onboardingCompleted))
                        setOnboardingNeedsAttention(
                          !updated.onboardingCompleted || getOnboardingSkipped(updated),
                        )
                      }}
                    />
                  </div>
                )}

                {activeTab === 'settings' && (
                  <div className="space-y-8">
                    <header>
                      <p className="text-xs uppercase tracking-[0.25em] text-indigo-300/80">
                        Ustawienia
                      </p>
                      <h1 className="text-3xl font-bold leading-tight text-white sm:text-4xl">
                        Integracje
                      </h1>
                    </header>
                    <IntegrationsSettingsSection
                      onGarminSyncComplete={handleGarminSyncComplete}
                    />
                  </div>
                )}
              </>
            )}
          </>
        ) : (
          <div className="mt-10 rounded-xl border border-dashed border-slate-700 bg-slate-900/40 p-8 text-center text-slate-300">
            Zaloguj się, aby korzystać z planu, listy treningów i integracji.
          </div>
        )}
      </div>
    </div>
  )
}

export default App
