import { useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import client from '../api/client'

type YesNo = 'yes' | 'no'
type StressLevel = 'low' | 'medium' | 'high'
type WorkType = 'sedentary' | 'physical' | 'mixed'
type Surface = 'asphalt' | 'trail' | 'mixed'
type Gender = 'female' | 'male' | 'other'

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

type OnboardingForm = {
  age: string
  heightCm: string
  weightKg: string
  gender: Gender
  city: string
  availableTerrain: string
  runningSince: string
  weeklyKm: string
  longestRunKm: string
  runsPerWeek: string
  time5k: string
  time10k: string
  timeHalfMarathon: string
  timeMarathon: string
  formScore: string
  afterBreak: YesNo
  breakDuration: string
  goalDistance: string
  raceDate: string
  goalTime: string
  goalPriority: string
  availableDays: string[]
  maxTrainingMinutes: string
  unavailableDays: string
  watch: string
  heartRateMonitor: string
  treadmill: YesNo
  gym: YesNo
  terrainAccess: string
  currentPain: string
  injuryHistory: string
  painDuringRun: string
  painAfterRun: string
  restingHr: string
  maxHr: string
  hasHrZones: YesNo
  hrZone1: string
  hrZone2: string
  hrZone3: string
  hrZone4: string
  hrZone5: string
  preferredSurface: Surface
  trainingStyle: string
  doesStrengthCore: YesNo
  sleepHours: string
  workType: WorkType
  stressLevel: StressLevel
}

const initialForm: OnboardingForm = {
  age: '',
  heightCm: '',
  weightKg: '',
  gender: 'other',
  city: '',
  availableTerrain: '',
  runningSince: '',
  weeklyKm: '',
  longestRunKm: '',
  runsPerWeek: '',
  time5k: '',
  time10k: '',
  timeHalfMarathon: '',
  timeMarathon: '',
  formScore: '',
  afterBreak: 'no',
  breakDuration: '',
  goalDistance: '',
  raceDate: '',
  goalTime: '',
  goalPriority: '1',
  availableDays: [],
  maxTrainingMinutes: '',
  unavailableDays: '',
  watch: '',
  heartRateMonitor: '',
  treadmill: 'no',
  gym: 'no',
  terrainAccess: '',
  currentPain: '',
  injuryHistory: '',
  painDuringRun: '',
  painAfterRun: '',
  restingHr: '',
  maxHr: '',
  hasHrZones: 'no',
  hrZone1: '',
  hrZone2: '',
  hrZone3: '',
  hrZone4: '',
  hrZone5: '',
  preferredSurface: 'mixed',
  trainingStyle: '',
  doesStrengthCore: 'no',
  sleepHours: '',
  workType: 'mixed',
  stressLevel: 'medium',
}

const steps = [
  'Dane podstawowe',
  'Doświadczenie biegowe',
  'Wyniki',
  'Cele',
  'Dostępność',
  'Sprzęt',
  'Zdrowie',
  'Tętno',
  'Preferencje',
  'Regeneracja',
]

const toNumber = (value: string): number | null => {
  if (!value.trim()) return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

type OnboardingProps = {
  onCompleted?: () => void
}

export default function Onboarding({ onCompleted }: OnboardingProps) {
  const [form, setForm] = useState<OnboardingForm>(initialForm)
  const [step, setStep] = useState(0)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  const isLastStep = step === steps.length - 1
  const progress = useMemo(() => Math.round(((step + 1) / steps.length) * 100), [step])

  const setField = <K extends keyof OnboardingForm>(key: K, value: OnboardingForm[K]) => {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  const toggleDay = (day: string) => {
    setForm((prev) => {
      const exists = prev.availableDays.includes(day)
      return {
        ...prev,
        availableDays: exists
          ? prev.availableDays.filter((d) => d !== day)
          : [...prev.availableDays, day],
      }
    })
  }

  const buildPayload = () => {
    const races = [
      { distance: '5k', result: form.time5k || null },
      { distance: '10k', result: form.time10k || null },
      { distance: 'halfMarathon', result: form.timeHalfMarathon || null },
      { distance: 'marathon', result: form.timeMarathon || null },
    ].filter((r) => r.result)

    return {
      preferredRunDays: form.availableDays.join(','),
      preferredSurface: form.preferredSurface,
      goals: {
        distance: form.goalDistance || null,
        raceDate: form.raceDate || null,
        goalTime: form.goalTime || null,
        priority: toNumber(form.goalPriority),
        formScore: toNumber(form.formScore),
        trainingStyle: form.trainingStyle || null,
      },
      races,
      availability: {
        availableDays: form.availableDays,
        unavailableDays: form.unavailableDays
          .split(',')
          .map((d) => d.trim())
          .filter(Boolean),
        maxTrainingMinutes: toNumber(form.maxTrainingMinutes),
        runsPerWeek: toNumber(form.runsPerWeek),
        weeklyKm: toNumber(form.weeklyKm),
      },
      equipment: {
        watch: form.watch || null,
        heartRateMonitor: form.heartRateMonitor || null,
        treadmill: form.treadmill === 'yes',
        gym: form.gym === 'yes',
        terrainAccess: form.terrainAccess || null,
        availableTerrain: form.availableTerrain || null,
      },
      health: {
        currentPain: form.currentPain || null,
        injuryHistory: form.injuryHistory || null,
        painDuringRun: form.painDuringRun || null,
        painAfterRun: form.painAfterRun || null,
        afterBreak: form.afterBreak === 'yes',
        breakDuration: form.breakDuration || null,
      },
      hrZones: {
        restingHr: toNumber(form.restingHr),
        maxHr: toNumber(form.maxHr),
        hasHrZones: form.hasHrZones === 'yes',
        zones: {
          z1: form.hrZone1 || null,
          z2: form.hrZone2 || null,
          z3: form.hrZone3 || null,
          z4: form.hrZone4 || null,
          z5: form.hrZone5 || null,
        },
      },
      constraints: {
        age: toNumber(form.age),
        heightCm: toNumber(form.heightCm),
        weightKg: toNumber(form.weightKg),
        gender: form.gender,
        city: form.city || null,
        runningSince: form.runningSince || null,
        longestRunKm: toNumber(form.longestRunKm),
        sleepHours: toNumber(form.sleepHours),
        workType: form.workType,
        stressLevel: form.stressLevel,
        strengthCore: form.doesStrengthCore === 'yes',
      },
    }
  }

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    setMessage('')
    setIsSubmitting(true)
    try {
      const payload = buildPayload()
      await client.put('/me/profile', payload)
      setMessage('Profil został zapisany.')
      if (onCompleted) {
        onCompleted()
      }
    } catch {
      setError('Nie udało się zapisać formularza. Spróbuj ponownie.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <form onSubmit={onSubmit} className="space-y-6 rounded border border-slate-200 p-6">
      <div>
        <p className="text-sm text-slate-600">
          Krok {step + 1} z {steps.length}: {steps[step]}
        </p>
        <div className="mt-2 h-2 w-full rounded bg-slate-200">
          <div className="h-2 rounded bg-slate-800" style={{ width: `${progress}%` }} />
        </div>
      </div>

      {step === 0 && (
        <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
          <input className="rounded border p-2" placeholder="Wiek" value={form.age} onChange={(e) => setField('age', e.target.value)} />
          <input className="rounded border p-2" placeholder="Wzrost (cm)" value={form.heightCm} onChange={(e) => setField('heightCm', e.target.value)} />
          <input className="rounded border p-2" placeholder="Masa ciała (kg)" value={form.weightKg} onChange={(e) => setField('weightKg', e.target.value)} />
          <select className="rounded border p-2" value={form.gender} onChange={(e) => setField('gender', e.target.value as Gender)}>
            <option value="female">Kobieta</option>
            <option value="male">Mężczyzna</option>
            <option value="other">Inna / nie podaję</option>
          </select>
        </section>
      )}

      {step === 1 && (
        <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
          <input className="rounded border p-2" placeholder="Od kiedy biegasz? (np. 2 lata)" value={form.runningSince} onChange={(e) => setField('runningSince', e.target.value)} />
          <input className="rounded border p-2" placeholder="Tygodniowy kilometraż" value={form.weeklyKm} onChange={(e) => setField('weeklyKm', e.target.value)} />
          <input className="rounded border p-2" placeholder="Najdłuższy bieg (km)" value={form.longestRunKm} onChange={(e) => setField('longestRunKm', e.target.value)} />
          <input className="rounded border p-2" placeholder="Liczba treningów/tydzień" value={form.runsPerWeek} onChange={(e) => setField('runsPerWeek', e.target.value)} />
        </section>
      )}

      {step === 2 && (
        <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
          <input className="rounded border p-2" placeholder="Czas 5 km (mm:ss)" value={form.time5k} onChange={(e) => setField('time5k', e.target.value)} />
          <input className="rounded border p-2" placeholder="Czas 10 km (mm:ss)" value={form.time10k} onChange={(e) => setField('time10k', e.target.value)} />
          <input className="rounded border p-2" placeholder="Czas półmaraton (hh:mm:ss)" value={form.timeHalfMarathon} onChange={(e) => setField('timeHalfMarathon', e.target.value)} />
          <input className="rounded border p-2" placeholder="Czas maraton (hh:mm:ss)" value={form.timeMarathon} onChange={(e) => setField('timeMarathon', e.target.value)} />
          <input className="rounded border p-2" placeholder="Forma (1-10)" value={form.formScore} onChange={(e) => setField('formScore', e.target.value)} />
          <select className="rounded border p-2" value={form.afterBreak} onChange={(e) => setField('afterBreak', e.target.value as YesNo)}>
            <option value="no">Bez przerwy</option>
            <option value="yes">Po przerwie</option>
          </select>
          {form.afterBreak === 'yes' && (
            <input className="rounded border p-2 md:col-span-2" placeholder="Długość przerwy (np. 3 tygodnie)" value={form.breakDuration} onChange={(e) => setField('breakDuration', e.target.value)} />
          )}
        </section>
      )}

      {step === 3 && (
        <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
          <input className="rounded border p-2" placeholder="Docelowy dystans (np. półmaraton)" value={form.goalDistance} onChange={(e) => setField('goalDistance', e.target.value)} />
          <input className="rounded border p-2" type="date" value={form.raceDate} onChange={(e) => setField('raceDate', e.target.value)} />
          <input className="rounded border p-2" placeholder="Cel czasowy (np. 1:39:00)" value={form.goalTime} onChange={(e) => setField('goalTime', e.target.value)} />
          <input className="rounded border p-2" placeholder="Priorytet (1-3)" value={form.goalPriority} onChange={(e) => setField('goalPriority', e.target.value)} />
        </section>
      )}

      {step === 4 && (
        <section className="space-y-3">
          <div className="flex flex-wrap gap-3">
            {DAYS.map((day) => (
              <label key={day} className="inline-flex items-center gap-2 rounded border px-2 py-1">
                <input type="checkbox" checked={form.availableDays.includes(day)} onChange={() => toggleDay(day)} />
                {day}
              </label>
            ))}
          </div>
          <input className="w-full rounded border p-2" placeholder="Maksymalny czas treningu (min)" value={form.maxTrainingMinutes} onChange={(e) => setField('maxTrainingMinutes', e.target.value)} />
          <input className="w-full rounded border p-2" placeholder="Dni niedostępne (oddziel przecinkami)" value={form.unavailableDays} onChange={(e) => setField('unavailableDays', e.target.value)} />
        </section>
      )}

      {step === 5 && (
        <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
          <input className="rounded border p-2" placeholder="Zegarek (model lub brak)" value={form.watch} onChange={(e) => setField('watch', e.target.value)} />
          <input className="rounded border p-2" placeholder="Pomiar tętna (np. pas HR)" value={form.heartRateMonitor} onChange={(e) => setField('heartRateMonitor', e.target.value)} />
          <select className="rounded border p-2" value={form.treadmill} onChange={(e) => setField('treadmill', e.target.value as YesNo)}>
            <option value="yes">Bieżnia: tak</option>
            <option value="no">Bieżnia: nie</option>
          </select>
          <select className="rounded border p-2" value={form.gym} onChange={(e) => setField('gym', e.target.value as YesNo)}>
            <option value="yes">Siłownia: tak</option>
            <option value="no">Siłownia: nie</option>
          </select>
          <input className="rounded border p-2 md:col-span-2" placeholder="Teren (np. las, asfalt, góry)" value={form.terrainAccess} onChange={(e) => setField('terrainAccess', e.target.value)} />
        </section>
      )}

      {step === 6 && (
        <section className="grid grid-cols-1 gap-3">
          <input className="rounded border p-2" placeholder="Aktualne bóle / urazy" value={form.currentPain} onChange={(e) => setField('currentPain', e.target.value)} />
          <input className="rounded border p-2" placeholder="Historia kontuzji (12 miesięcy)" value={form.injuryHistory} onChange={(e) => setField('injuryHistory', e.target.value)} />
          <input className="rounded border p-2" placeholder="Ból podczas biegania?" value={form.painDuringRun} onChange={(e) => setField('painDuringRun', e.target.value)} />
          <input className="rounded border p-2" placeholder="Ból po biegu / rano?" value={form.painAfterRun} onChange={(e) => setField('painAfterRun', e.target.value)} />
        </section>
      )}

      {step === 7 && (
        <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
          <input className="rounded border p-2" placeholder="Tętno spoczynkowe" value={form.restingHr} onChange={(e) => setField('restingHr', e.target.value)} />
          <input className="rounded border p-2" placeholder="Tętno maksymalne" value={form.maxHr} onChange={(e) => setField('maxHr', e.target.value)} />
          <select className="rounded border p-2 md:col-span-2" value={form.hasHrZones} onChange={(e) => setField('hasHrZones', e.target.value as YesNo)}>
            <option value="no">Brak stref HR</option>
            <option value="yes">Mam strefy HR</option>
          </select>
          {form.hasHrZones === 'yes' && (
            <>
              <input className="rounded border p-2" placeholder="Strefa Z1" value={form.hrZone1} onChange={(e) => setField('hrZone1', e.target.value)} />
              <input className="rounded border p-2" placeholder="Strefa Z2" value={form.hrZone2} onChange={(e) => setField('hrZone2', e.target.value)} />
              <input className="rounded border p-2" placeholder="Strefa Z3" value={form.hrZone3} onChange={(e) => setField('hrZone3', e.target.value)} />
              <input className="rounded border p-2" placeholder="Strefa Z4" value={form.hrZone4} onChange={(e) => setField('hrZone4', e.target.value)} />
              <input className="rounded border p-2 md:col-span-2" placeholder="Strefa Z5" value={form.hrZone5} onChange={(e) => setField('hrZone5', e.target.value)} />
            </>
          )}
        </section>
      )}

      {step === 8 && (
        <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
          <select className="rounded border p-2" value={form.preferredSurface} onChange={(e) => setField('preferredSurface', e.target.value as Surface)}>
            <option value="asphalt">Asfalt</option>
            <option value="trail">Las / trail</option>
            <option value="mixed">Mieszany</option>
          </select>
          <input className="rounded border p-2" placeholder="Krótkie szybsze / długie spokojne" value={form.trainingStyle} onChange={(e) => setField('trainingStyle', e.target.value)} />
          <select className="rounded border p-2 md:col-span-2" value={form.doesStrengthCore} onChange={(e) => setField('doesStrengthCore', e.target.value as YesNo)}>
            <option value="yes">Siła / core: tak</option>
            <option value="no">Siła / core: nie</option>
          </select>
        </section>
      )}

      {step === 9 && (
        <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
          <input className="rounded border p-2" placeholder="Sen (h / noc)" value={form.sleepHours} onChange={(e) => setField('sleepHours', e.target.value)} />
          <select className="rounded border p-2" value={form.workType} onChange={(e) => setField('workType', e.target.value as WorkType)}>
            <option value="sedentary">Praca siedząca</option>
            <option value="physical">Praca fizyczna</option>
            <option value="mixed">Praca mieszana</option>
          </select>
          <select className="rounded border p-2 md:col-span-2" value={form.stressLevel} onChange={(e) => setField('stressLevel', e.target.value as StressLevel)}>
            <option value="low">Stres niski</option>
            <option value="medium">Stres średni</option>
            <option value="high">Stres wysoki</option>
          </select>
        </section>
      )}

      {error && <p className="text-sm text-red-600">{error}</p>}
      {message && <p className="text-sm text-emerald-700">{message}</p>}

      <div className="flex items-center justify-between">
        <button
          type="button"
          className="rounded border px-4 py-2 disabled:opacity-50"
          disabled={step === 0}
          onClick={() => setStep((prev) => Math.max(0, prev - 1))}
        >
          Wstecz
        </button>

        {isLastStep ? (
          <button className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50" type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Zapisywanie...' : 'Zapisz onboarding'}
          </button>
        ) : (
          <button
            type="button"
            className="rounded bg-slate-900 px-4 py-2 text-white"
            onClick={() => setStep((prev) => Math.min(steps.length - 1, prev + 1))}
          >
            Dalej
          </button>
        )}
      </div>
    </form>
  )
}
