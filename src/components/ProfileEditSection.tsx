import { useState, useEffect, useCallback } from 'react'
import {
  getMyProfile,
  updateMyProfile,
  type UserProfile,
  type UpdateProfilePayload,
} from '../api/profile'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type RaceEntry = {
  name?: string
  date: string
  distanceKm: number
  priority?: 'A' | 'B' | 'C'
  targetTime?: string
}

type QualityBreakdownItem = {
  points: number
  max: number
  ok: boolean
}

const QUALITY_LABELS: Record<string, { label: string; hint: string }> = {
  runningDays: {
    label: 'Dni treningowe',
    hint: 'Podaj, które dni tygodnia biegasz.',
  },
  primaryRace: {
    label: 'Start docelowy',
    hint: 'Dodaj przynajmniej jeden przyszły start (priorytet A/B/C).',
  },
  maxSessionMin: {
    label: 'Maks. czas sesji',
    hint: 'Podaj maksymalny czas jednego treningu w minutach.',
  },
  health: {
    label: 'Dane zdrowotne',
    hint: 'Wypełnij sekcję zdrowia (historia kontuzji, aktualny ból).',
  },
  equipment: {
    label: 'Sprzęt',
    hint: 'Określ, czy masz zegarek i czujnik HR.',
  },
  hrZones: {
    label: 'Strefy HR',
    hint: 'Uzupełnij zakresy tętna dla 5 stref (Z1–Z5).',
  },
  surface: {
    label: 'Preferowane podłoże',
    hint: 'Wybierz preferowane podłoże treningowe.',
  },
}

const DAYS_OF_WEEK = [
  { id: 'mon', label: 'Pon' },
  { id: 'tue', label: 'Wt' },
  { id: 'wed', label: 'Śr' },
  { id: 'thu', label: 'Czw' },
  { id: 'fri', label: 'Pt' },
  { id: 'sat', label: 'Sob' },
  { id: 'sun', label: 'Nd' },
]

const SURFACES = [
  { id: 'TRAIL', label: 'Trail' },
  { id: 'ROAD', label: 'Droga asfaltowa' },
  { id: 'MIXED', label: 'Mieszane' },
]

const DISTANCE_PRESETS = [
  { value: 5, label: '5 km' },
  { value: 10, label: '10 km' },
  { value: 15, label: '15 km' },
  { value: 21.1, label: 'Półmaraton (21,1 km)' },
  { value: 42.2, label: 'Maraton (42,2 km)' },
  { value: 0, label: 'Inny dystans' },
]

const PRIORITIES = [
  { id: 'A', label: 'A — Główny start' },
  { id: 'B', label: 'B — Ważny' },
  { id: 'C', label: 'C — Poboczny' },
]

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function emptyRace(): RaceEntry {
  return { name: '', date: '', distanceKm: 10, priority: 'A', targetTime: '' }
}

function runningDaysFromAvailability(profile: UserProfile): string[] {
  const avail = profile.availability as { runningDays?: string[] } | null
  return avail?.runningDays ?? []
}

function maxSessionMinFromAvailability(profile: UserProfile): number | null {
  const avail = profile.availability as { maxSessionMin?: number } | null
  return avail?.maxSessionMin ?? null
}

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function SectionHeader({ title, subtitle }: { title: string; subtitle?: string }) {
  return (
    <div className="mb-4">
      <h3 className="text-lg font-semibold text-white">{title}</h3>
      {subtitle && <p className="mt-0.5 text-sm text-slate-400">{subtitle}</p>}
    </div>
  )
}

function Card({ children }: { children: React.ReactNode }) {
  return (
    <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-5">
      {children}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Quality Score panel (EP-013)
// ---------------------------------------------------------------------------

function ProfileQualityScore({ quality }: { quality: Record<string, unknown> | null | undefined }) {
  if (!quality) return null

  const score = typeof quality.score === 'number' ? quality.score : 0
  const breakdown = (quality.breakdown ?? {}) as Record<string, QualityBreakdownItem>

  const color = score >= 70 ? 'text-emerald-400' : score >= 40 ? 'text-yellow-400' : 'text-rose-400'
  const barColor = score >= 70 ? 'bg-emerald-500' : score >= 40 ? 'bg-yellow-500' : 'bg-rose-500'

  const missing = Object.entries(breakdown)
    .filter(([, v]) => !v.ok)
    .map(([k]) => QUALITY_LABELS[k])
    .filter(Boolean)

  return (
    <Card>
      <SectionHeader
        title="Jakość profilu"
        subtitle="Wyższy wynik = dokładniejszy plan i lepszy feedback."
      />
      <div className="flex items-center gap-4">
        <span className={`text-4xl font-bold ${color}`}>{score}</span>
        <span className="text-slate-400">/100</span>
        <div className="flex-1">
          <div className="h-2 w-full rounded-full bg-slate-700">
            <div
              className={`h-2 rounded-full ${barColor} transition-all duration-500`}
              style={{ width: `${score}%` }}
            />
          </div>
        </div>
      </div>

      {missing.length > 0 && (
        <div className="mt-4">
          <p className="mb-2 text-sm font-medium text-slate-300">Co uzupełnić, żeby podnieść wynik:</p>
          <ul className="space-y-1.5">
            {missing.map((m) => (
              <li key={m.label} className="flex items-start gap-2 text-sm text-slate-400">
                <span className="mt-0.5 flex-shrink-0 text-rose-400">✗</span>
                <span>
                  <span className="font-medium text-slate-300">{m.label}</span>
                  {' — '}
                  {m.hint}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {missing.length === 0 && (
        <p className="mt-3 text-sm text-emerald-400">Profil w pełni wypełniony — świetna robota!</p>
      )}
    </Card>
  )
}

// ---------------------------------------------------------------------------
// Races manager (EP-012)
// ---------------------------------------------------------------------------

type RacesManagerProps = {
  races: RaceEntry[]
  onSave: (races: RaceEntry[]) => Promise<void>
  saving: boolean
}

function RacesManager({ races, onSave, saving }: RacesManagerProps) {
  const [editingIndex, setEditingIndex] = useState<number | null>(null)
  const [draft, setDraft] = useState<RaceEntry>(emptyRace())
  const [isAdding, setIsAdding] = useState(false)
  const [customDistance, setCustomDistance] = useState<string>('')

  const handleAdd = () => {
    setDraft(emptyRace())
    setCustomDistance('')
    setIsAdding(true)
    setEditingIndex(null)
  }

  const handleEdit = (idx: number) => {
    const r = races[idx]
    setDraft({ ...r })
    const preset = DISTANCE_PRESETS.find((p) => p.value > 0 && p.value === r.distanceKm)
    setCustomDistance(preset ? '' : String(r.distanceKm))
    setIsAdding(false)
    setEditingIndex(idx)
  }

  const handleDelete = async (idx: number) => {
    const updated = races.filter((_, i) => i !== idx)
    await onSave(updated)
  }

  const handleCancel = () => {
    setIsAdding(false)
    setEditingIndex(null)
  }

  const handleSaveDraft = async () => {
    const distance = customDistance ? parseFloat(customDistance) : draft.distanceKm
    if (!draft.date || !distance || distance <= 0) return
    const entry: RaceEntry = { ...draft, distanceKm: distance }
    if (!entry.name) delete entry.name
    if (!entry.targetTime) delete entry.targetTime
    if (!entry.priority) delete entry.priority

    let updated: RaceEntry[]
    if (editingIndex !== null) {
      updated = races.map((r, i) => (i === editingIndex ? entry : r))
    } else {
      updated = [...races, entry]
    }
    await onSave(updated)
    setIsAdding(false)
    setEditingIndex(null)
  }

  const showForm = isAdding || editingIndex !== null

  const formatDistanceDisplay = (km: number) => {
    const preset = DISTANCE_PRESETS.find((p) => p.value > 0 && p.value === km)
    return preset ? preset.label : `${km} km`
  }

  return (
    <Card>
      <div className="flex items-center justify-between">
        <SectionHeader
          title="Starty i cele"
          subtitle="Podaj swoje planowane starty — system dopasuje plan do faz build/peak/taper."
        />
        {!showForm && (
          <button
            onClick={handleAdd}
            className="flex-shrink-0 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"
          >
            + Dodaj start
          </button>
        )}
      </div>

      {races.length === 0 && !showForm && (
        <p className="text-sm text-slate-500">Nie masz jeszcze żadnego startu.</p>
      )}

      {races.length > 0 && !showForm && (
        <div className="space-y-2">
          {races.map((r, idx) => (
            <div
              key={idx}
              className="flex items-center justify-between rounded-lg border border-slate-700 bg-slate-800/50 px-4 py-3"
            >
              <div>
                <div className="flex items-center gap-2">
                  {r.priority && (
                    <span
                      className={`rounded px-1.5 py-0.5 text-xs font-bold ${
                        r.priority === 'A'
                          ? 'bg-indigo-700 text-indigo-100'
                          : r.priority === 'B'
                          ? 'bg-slate-700 text-slate-200'
                          : 'bg-slate-800 text-slate-400'
                      }`}
                    >
                      {r.priority}
                    </span>
                  )}
                  <span className="font-medium text-white">
                    {r.name || formatDistanceDisplay(r.distanceKm)}
                  </span>
                </div>
                <div className="mt-0.5 flex items-center gap-3 text-xs text-slate-400">
                  <span>{r.date}</span>
                  {r.name && <span>{formatDistanceDisplay(r.distanceKm)}</span>}
                  {r.targetTime && <span>Cel: {r.targetTime}</span>}
                </div>
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => handleEdit(idx)}
                  className="rounded px-2 py-1 text-xs text-indigo-400 hover:text-indigo-300"
                >
                  Edytuj
                </button>
                <button
                  onClick={() => handleDelete(idx)}
                  disabled={saving}
                  className="rounded px-2 py-1 text-xs text-rose-400 hover:text-rose-300"
                >
                  Usuń
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {showForm && (
        <div className="mt-2 rounded-lg border border-slate-700 bg-slate-800/50 p-4">
          <p className="mb-3 text-sm font-medium text-slate-300">
            {editingIndex !== null ? 'Edytuj start' : 'Nowy start'}
          </p>
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-xs text-slate-400">Nazwa (opcjonalna)</label>
              <input
                type="text"
                value={draft.name ?? ''}
                onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))}
                placeholder="np. Maraton Wrocław"
                className="w-full rounded border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-400">Data startu *</label>
              <input
                type="date"
                value={draft.date}
                onChange={(e) => setDraft((d) => ({ ...d, date: e.target.value }))}
                className="w-full rounded border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-400">Dystans *</label>
              <select
                value={
                  DISTANCE_PRESETS.find((p) => p.value > 0 && p.value === draft.distanceKm)
                    ? draft.distanceKm
                    : 0
                }
                onChange={(e) => {
                  const v = parseFloat(e.target.value)
                  if (v > 0) {
                    setDraft((d) => ({ ...d, distanceKm: v }))
                    setCustomDistance('')
                  } else {
                    setCustomDistance('')
                  }
                }}
                className="w-full rounded border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none"
              >
                {DISTANCE_PRESETS.map((p) => (
                  <option key={p.value} value={p.value}>
                    {p.label}
                  </option>
                ))}
              </select>
              {(DISTANCE_PRESETS.find((p) => p.value > 0 && p.value === draft.distanceKm) === undefined) && (
                <input
                  type="number"
                  value={customDistance}
                  onChange={(e) => setCustomDistance(e.target.value)}
                  placeholder="Dystans w km"
                  min={0.1}
                  step={0.1}
                  className="mt-2 w-full rounded border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none"
                />
              )}
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-400">Priorytet</label>
              <select
                value={draft.priority ?? 'A'}
                onChange={(e) =>
                  setDraft((d) => ({ ...d, priority: e.target.value as 'A' | 'B' | 'C' }))
                }
                className="w-full rounded border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none"
              >
                {PRIORITIES.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs text-slate-400">
                Cel czasowy (opcjonalnie, format HH:MM:SS lub MM:SS)
              </label>
              <input
                type="text"
                value={draft.targetTime ?? ''}
                onChange={(e) => setDraft((d) => ({ ...d, targetTime: e.target.value }))}
                placeholder="np. 3:30:00 lub 48:00"
                className="w-full rounded border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none"
              />
            </div>
          </div>
          <div className="mt-4 flex gap-2">
            <button
              onClick={handleSaveDraft}
              disabled={saving || !draft.date || (!draft.distanceKm && !customDistance)}
              className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            >
              {saving ? 'Zapisuję…' : 'Zapisz'}
            </button>
            <button
              onClick={handleCancel}
              className="rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-300 hover:text-white"
            >
              Anuluj
            </button>
          </div>
        </div>
      )}
    </Card>
  )
}

// ---------------------------------------------------------------------------
// Main ProfileEditSection (EP-011)
// ---------------------------------------------------------------------------

type Props = {
  profile: UserProfile | null
  onProfileUpdated: (profile: UserProfile) => void
}

export default function ProfileEditSection({ profile, onProfileUpdated }: Props) {
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [localProfile, setLocalProfile] = useState<UserProfile | null>(profile)
  const [saveMsg, setSaveMsg] = useState<{ type: 'ok' | 'err'; text: string } | null>(null)

  // Form state derived from profile
  const [goals, setGoals] = useState('')
  const [selectedDays, setSelectedDays] = useState<string[]>([])
  const [maxSessionMin, setMaxSessionMin] = useState<string>('')
  const [currentPain, setCurrentPain] = useState(false)
  const [hasWatch, setHasWatch] = useState(false)
  const [hasHrSensor, setHasHrSensor] = useState(false)
  const [surface, setSurface] = useState('')

  const populateForm = useCallback((p: UserProfile) => {
    setGoals(p.goals ?? '')
    setSelectedDays(runningDaysFromAvailability(p))
    setMaxSessionMin(maxSessionMinFromAvailability(p)?.toString() ?? '')
    const health = p.health as { currentPain?: boolean } | null
    setCurrentPain(health?.currentPain ?? false)
    const equipment = p.equipment as { watch?: boolean; hrSensor?: boolean } | null
    setHasWatch(equipment?.watch ?? false)
    setHasHrSensor(equipment?.hrSensor ?? false)
    setSurface(p.preferredSurface ?? '')
  }, [])

  useEffect(() => {
    if (profile) {
      setLocalProfile(profile)
      populateForm(profile)
    } else {
      setLoading(true)
      getMyProfile()
        .then((p) => {
          setLocalProfile(p)
          populateForm(p)
          onProfileUpdated(p)
        })
        .catch(() => {
          setSaveMsg({ type: 'err', text: 'Nie udało się załadować profilu.' })
        })
        .finally(() => setLoading(false))
    }
  }, [profile, populateForm, onProfileUpdated])

  const toggleDay = (day: string) => {
    setSelectedDays((prev) =>
      prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day],
    )
  }

  const showMsg = (type: 'ok' | 'err', text: string) => {
    setSaveMsg({ type, text })
    setTimeout(() => setSaveMsg(null), 4000)
  }

  const handleSaveBasic = async () => {
    setSaving(true)
    try {
      const payload: UpdateProfilePayload = {
        goals: goals || undefined,
        preferredSurface: surface || undefined,
        availability: {
          runningDays: selectedDays,
          ...(maxSessionMin ? { maxSessionMin: parseInt(maxSessionMin, 10) } : {}),
        },
        health: {
          currentPain,
          injuryHistory: (localProfile?.health as { injuryHistory?: unknown[] } | null)?.injuryHistory ?? [],
        },
        equipment: { watch: hasWatch, hrSensor: hasHrSensor },
      }
      const updated = await updateMyProfile(payload)
      setLocalProfile(updated)
      onProfileUpdated(updated)
      showMsg('ok', 'Profil zapisany.')
    } catch {
      showMsg('err', 'Błąd zapisu profilu.')
    } finally {
      setSaving(false)
    }
  }

  const handleSaveRaces = async (races: RaceEntry[]) => {
    setSaving(true)
    try {
      const updated = await updateMyProfile({ races })
      setLocalProfile(updated)
      onProfileUpdated(updated)
      showMsg('ok', 'Starty zapisane.')
    } catch {
      showMsg('err', 'Błąd zapisu startów.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="text-center text-slate-400 py-12">Ładowanie profilu…</div>
    )
  }

  const races = (localProfile?.races ?? []) as RaceEntry[]

  return (
    <div className="space-y-6">
      {saveMsg && (
        <div
          className={`rounded-lg px-4 py-3 text-sm ${
            saveMsg.type === 'ok'
              ? 'bg-emerald-900/40 text-emerald-300'
              : 'bg-rose-900/40 text-rose-300'
          }`}
        >
          {saveMsg.text}
        </div>
      )}

      {/* EP-013: Quality score */}
      <ProfileQualityScore quality={localProfile?.quality as Record<string, unknown> | null | undefined} />

      {/* EP-012: Races */}
      <RacesManager races={races} onSave={handleSaveRaces} saving={saving} />

      {/* EP-011: Basic profile */}
      <Card>
        <SectionHeader
          title="Dane treningowe"
          subtitle="Uzupełnij, żeby plan był lepiej dopasowany do Ciebie."
        />

        <div className="space-y-5">
          {/* Goals */}
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-300">Cel treningowy</label>
            <input
              type="text"
              value={goals}
              onChange={(e) => setGoals(e.target.value)}
              placeholder="np. przebiec 10 km poniżej 50 minut"
              className="w-full rounded border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none"
            />
          </div>

          {/* Running days */}
          <div>
            <label className="mb-2 block text-sm font-medium text-slate-300">
              Dni treningowe
            </label>
            <div className="flex flex-wrap gap-2">
              {DAYS_OF_WEEK.map((d) => (
                <button
                  key={d.id}
                  type="button"
                  onClick={() => toggleDay(d.id)}
                  className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
                    selectedDays.includes(d.id)
                      ? 'bg-indigo-600 text-white'
                      : 'border border-slate-600 text-slate-400 hover:border-slate-400'
                  }`}
                >
                  {d.label}
                </button>
              ))}
            </div>
          </div>

          {/* Max session */}
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-300">
              Maks. czas sesji (minuty)
            </label>
            <input
              type="number"
              value={maxSessionMin}
              onChange={(e) => setMaxSessionMin(e.target.value)}
              placeholder="np. 75"
              min={15}
              max={300}
              className="w-36 rounded border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none"
            />
          </div>

          {/* Surface */}
          <div>
            <label className="mb-2 block text-sm font-medium text-slate-300">
              Preferowane podłoże
            </label>
            <div className="flex flex-wrap gap-2">
              {SURFACES.map((s) => (
                <button
                  key={s.id}
                  type="button"
                  onClick={() => setSurface(surface === s.id ? '' : s.id)}
                  className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
                    surface === s.id
                      ? 'bg-indigo-600 text-white'
                      : 'border border-slate-600 text-slate-400 hover:border-slate-400'
                  }`}
                >
                  {s.label}
                </button>
              ))}
            </div>
          </div>

          {/* Health */}
          <div>
            <label className="mb-2 block text-sm font-medium text-slate-300">Zdrowie</label>
            <label className="flex cursor-pointer items-center gap-3">
              <input
                type="checkbox"
                checked={currentPain}
                onChange={(e) => setCurrentPain(e.target.checked)}
                className="h-4 w-4 rounded border-slate-600 bg-slate-700 text-indigo-600 focus:ring-indigo-500"
              />
              <span className="text-sm text-slate-300">
                Mam aktualny ból lub dyskomfort (plan zostanie złagodzony)
              </span>
            </label>
            <p className="mt-1 text-xs text-slate-500">
              MarcinCoach nie zastępuje porady lekarza ani fizjoterapeuty.
            </p>
          </div>

          {/* Equipment */}
          <div>
            <label className="mb-2 block text-sm font-medium text-slate-300">Sprzęt</label>
            <div className="space-y-2">
              <label className="flex cursor-pointer items-center gap-3">
                <input
                  type="checkbox"
                  checked={hasWatch}
                  onChange={(e) => setHasWatch(e.target.checked)}
                  className="h-4 w-4 rounded border-slate-600 bg-slate-700 text-indigo-600 focus:ring-indigo-500"
                />
                <span className="text-sm text-slate-300">Zegarek sportowy (GPS)</span>
              </label>
              <label className="flex cursor-pointer items-center gap-3">
                <input
                  type="checkbox"
                  checked={hasHrSensor}
                  onChange={(e) => setHasHrSensor(e.target.checked)}
                  className="h-4 w-4 rounded border-slate-600 bg-slate-700 text-indigo-600 focus:ring-indigo-500"
                />
                <span className="text-sm text-slate-300">Czujnik tętna (HR)</span>
              </label>
            </div>
          </div>

          <button
            onClick={handleSaveBasic}
            disabled={saving}
            className="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
          >
            {saving ? 'Zapisuję…' : 'Zapisz profil'}
          </button>
        </div>
      </Card>
    </div>
  )
}
