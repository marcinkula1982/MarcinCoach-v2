import type React from 'react'
import { useMemo } from 'react'
import type { WorkoutListItem } from '../api/workouts'

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

const formatDuration = (totalSec: number) => {
  const hours = Math.floor(totalSec / 3600)
  const minutes = Math.floor((totalSec % 3600) / 60)
  const seconds = totalSec % 60
  if (hours > 0) {
    return `${hours}h ${minutes.toString().padStart(2, '0')}m`
  }
  return `${minutes}m ${seconds.toString().padStart(2, '0')}s`
}

interface WorkoutsListProps {
  workouts: WorkoutListItem[]
  loggedInUser: string
  onLoadWorkout: (id: string) => void
  onDeleteWorkout: (id: string, e: React.MouseEvent) => void
}

const WorkoutsList = ({
  workouts,
  loggedInUser,
  onLoadWorkout,
  onDeleteWorkout,
}: WorkoutsListProps) => {
  const totals = useMemo(() => {
    const totalWorkouts = workouts.length
    const totalDistanceMeters = workouts.reduce(
      (sum, w) => sum + (w.summary.trimmed?.distanceM ?? w.summary.original?.distanceM ?? 0),
      0,
    )
    const totalDistanceKm = totalDistanceMeters / 1000
    const totalDurationSeconds = workouts.reduce(
      (sum, w) =>
        sum +
        (w.summary.trimmed?.durationSec ??
          w.summary.original?.durationSec ??
          0),
      0,
    )

    const now = new Date()
    const dayOfWeek = (now.getDay() + 6) % 7 // 0 = poniedziałek
    const startOfWeek = new Date(now)
    startOfWeek.setHours(0, 0, 0, 0)
    startOfWeek.setDate(now.getDate() - dayOfWeek)

    const endOfToday = new Date(now)
    endOfToday.setHours(23, 59, 59, 999)

    const isThisWeek = (dateStr: string) => {
      const d = new Date(dateStr)
      return d >= startOfWeek && d <= endOfToday
    }

    const weekWorkouts = workouts.filter((w) => isThisWeek(w.createdAt))
    const weekTotalWorkouts = weekWorkouts.length
    const weekTotalDistanceMeters = weekWorkouts.reduce(
      (sum, w) =>
        sum +
        (w.summary.trimmed?.distanceM ?? w.summary.original?.distanceM ?? 0),
      0,
    )
    const weekTotalDistanceKm = weekTotalDistanceMeters / 1000
    const weekTotalDurationSeconds = weekWorkouts.reduce(
      (sum, w) =>
        sum +
        (w.summary.trimmed?.durationSec ??
          w.summary.original?.durationSec ??
          0),
      0,
    )

    const fatigueDates = workouts
      .filter((w) => {
        if (!w.workoutMeta?.fatigueFlag) return false
        const dateStr = w.summary?.startTimeIso ?? w.createdAt
        const d = new Date(dateStr)
        const now = new Date()
        const diffDays = (now.getTime() - d.getTime()) / (1000 * 60 * 60 * 24)
        return diffDays <= 7
      })
      .map((w) => new Date(w.summary?.startTimeIso ?? w.createdAt))
      .sort((a, b) => b.getTime() - a.getTime())

    const fatigueLast7Days = fatigueDates.length

    const lastFatigueDate = fatigueDates[0] ?? null

    const fatigueRec = (() => {
      if (!lastFatigueDate) return null
      const now = new Date()
      const diffDays = (now.getTime() - lastFatigueDate.getTime()) / (1000 * 60 * 60 * 24)
      return diffDays < 1.5
        ? 'Rekomendacja: dziś tylko easy 30–50 min albo wolne (bez akcentu).'
        : 'Rekomendacja: kolejny akcent dopiero po 1 dniu spokojnym.'
    })()

    return {
      totalWorkouts,
      totalDistanceKm,
      totalDurationSeconds,
      weekTotalWorkouts,
      weekTotalDistanceKm,
      weekTotalDurationSeconds,
      fatigueLast7Days,
      fatigueRec,
    }
  }, [workouts])

  return (
    <section className="max-w-4xl mx-auto px-4 py-6">
      <h2 className="text-lg font-semibold mb-2">Twoje treningi</h2>
      <p className="text-sm text-slate-400 mb-3">
        Lista zapisanych treningów dla użytkownika {loggedInUser}.
      </p>

      <div className="mb-4 flex flex-col gap-2 text-sm text-slate-200">
        <div className="flex gap-6">
          <div>
            <div className="text-xs text-slate-500">Liczba treningów (łącznie)</div>
            <div className="font-semibold">{totals.totalWorkouts}</div>
          </div>
          <div>
            <div className="text-xs text-slate-500">Łączny dystans</div>
            <div className="font-semibold">{totals.totalDistanceKm.toFixed(2)} km</div>
          </div>
          <div>
            <div className="text-xs text-slate-500">Łączny czas</div>
            <div className="font-semibold">
              {totals.totalWorkouts > 0 ? formatDuration(totals.totalDurationSeconds) : '—'}
            </div>
          </div>
        </div>

        <div className="flex gap-6">
          <div>
            <div className="text-xs text-slate-500">Treningi w tym tygodniu</div>
            <div className="font-semibold">{totals.weekTotalWorkouts}</div>
          </div>
          <div>
            <div className="text-xs text-slate-500">Dystans w tym tygodniu</div>
            <div className="font-semibold">{totals.weekTotalDistanceKm.toFixed(2)} km</div>
          </div>
          <div>
            <div className="text-xs text-slate-500">Czas w tym tygodniu</div>
            <div className="font-semibold">
              {totals.weekTotalWorkouts > 0
                ? formatDuration(totals.weekTotalDurationSeconds)
                : '—'}
            </div>
          </div>
        </div>
      </div>

      {totals.fatigueLast7Days >= 2 && (
        <div className="mb-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-200">
          ⚠️ W ostatnich 7 dniach wykryto {totals.fatigueLast7Days} treningi z możliwym zmęczeniem.
          {totals.fatigueRec && (
            <div className="mt-1 text-amber-100">{totals.fatigueRec}</div>
          )}
        </div>
      )}

      <div className="space-y-3">
        {workouts.map((w) => {
          const distanceM =
            w.summary.trimmed?.distanceM ??
            w.summary.original?.distanceM ??
            0
          const durationSec =
            w.summary.trimmed?.durationSec ??
            w.summary.original?.durationSec ??
            0
          const distanceKm = (distanceM / 1000).toFixed(2)
          const displayedDate = w.summary.startTimeIso ?? w.createdAt

          return (
            <div
              key={w.id}
              onClick={() => onLoadWorkout(String(w.id))}
              className="rounded-lg border border-slate-800 bg-slate-900/60 px-4 py-3 text-sm text-slate-100 cursor-pointer transition hover:border-indigo-400 hover:bg-slate-800/70"
            >
              <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div className="font-semibold flex items-center gap-2">
                  <span>{displayedDate ? new Date(displayedDate).toLocaleString('pl-PL') : '—'}</span>
                  {w.workoutMeta?.fatigueFlag === true && (
                    <span title="Możliwe zmęczenie (wysokie RPE przy niskim obciążeniu)">⚠️</span>
                  )}
                </div>
                <div className="flex items-center gap-4 text-slate-200">
                  <span>Dystans: {distanceKm} km</span>
                  <span>Czas: {formatSeconds(durationSec)}</span>
                  <button
                    className="ml-4 text-sm text-red-400 hover:text-red-300"
                    onClick={(e) => onDeleteWorkout(String(w.id), e)}
                  >
                    Usuń
                  </button>
                </div>
              </div>
            </div>
          )
        })}
        {workouts.length === 0 && (
          <div className="rounded-lg border border-dashed border-slate-700 bg-slate-900/30 px-4 py-3 text-sm text-slate-300">
            Brak zapisanych treningów.
          </div>
        )}
      </div>
    </section>
  )
}

export default WorkoutsList

