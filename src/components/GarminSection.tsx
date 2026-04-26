// src/components/GarminSection.tsx
import { useEffect, useState } from 'react'
import {
  garminStatus,
  garminConnect,
  garminSync,
  type GarminSyncResponse,
} from '../api/garmin'

type ViewState = 'loading' | 'connected' | 'form' | 'error'

const SYNC_OPTIONS = [
  { label: 'Ostatnie 7 dni', days: 7 },
  { label: 'Ostatnie 14 dni', days: 14 },
  { label: 'Ostatnie 30 dni', days: 30 },
  { label: 'Ostatnie 90 dni', days: 90 },
]

interface Props {
  refreshToken: number
  onSyncComplete?: (result: GarminSyncResponse) => void | Promise<void>
}

export default function GarminSection({ refreshToken, onSyncComplete }: Props) {
  const [view, setView] = useState<ViewState>('loading')
  const [displayName, setDisplayName] = useState<string | null>(null)

  // connect form
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [connecting, setConnecting] = useState(false)
  const [connectError, setConnectError] = useState<string | null>(null)

  // sync
  const [syncDays, setSyncDays] = useState(30)
  const [syncActivityType, setSyncActivityType] = useState<string>('running')
  const [syncing, setSyncing] = useState(false)
  const [syncResult, setSyncResult] = useState<GarminSyncResponse | null>(null)
  const [syncError, setSyncError] = useState<string | null>(null)

  useEffect(() => {
    setView('loading')
    setSyncResult(null)
    setSyncError(null)
    garminStatus()
      .then((data) => {
        if (data.connected) {
          setDisplayName(data.displayName ?? data.fullName ?? null)
          setView('connected')
        } else {
          setView('form')
        }
      })
      .catch(() => setView('form'))
  }, [refreshToken])

  const handleConnect = async () => {
    if (!email.trim() || !password.trim()) return
    setConnecting(true)
    setConnectError(null)
    try {
      const res = await garminConnect(email.trim(), password.trim())
      setDisplayName(res.displayName ?? res.fullName ?? null)
      setPassword('')
      setView('connected')
    } catch (err: any) {
      const msg =
        err?.response?.data?.message ||
        err?.response?.data?.error ||
        err?.message ||
        'Błąd połączenia z Garmin'
      setConnectError(String(msg))
    } finally {
      setConnecting(false)
    }
  }

  const handleSync = async () => {
    setSyncing(true)
    setSyncResult(null)
    setSyncError(null)
    try {
      const now = new Date()
      const from = new Date(now)
      from.setDate(from.getDate() - syncDays)
      const result = await garminSync(from.toISOString(), now.toISOString(), syncActivityType || null)
      setSyncResult(result)
      await onSyncComplete?.(result)
    } catch (err: any) {
      const msg =
        err?.response?.data?.message ||
        err?.response?.data?.error ||
        err?.message ||
        'Błąd synchronizacji'
      setSyncError(String(msg))
    } finally {
      setSyncing(false)
    }
  }

  return (
    <section className="rounded-2xl bg-slate-900/60 p-6 shadow-lg ring-1 ring-white/5 mt-8">
      <div className="mb-4">
        <p className="text-xs uppercase tracking-[0.2em] text-indigo-300/80">Integracja</p>
        <h2 className="text-xl font-semibold text-white">Garmin Connect</h2>
      </div>

      {view === 'loading' && (
        <p className="text-sm text-slate-400">Sprawdzanie statusu...</p>
      )}

      {view === 'form' && (
        <div className="space-y-4 max-w-sm">
          <p className="text-sm text-slate-300">
            Podaj dane logowania do konta Garmin Connect.
            Hasło nie jest przechowywane po nawiązaniu połączenia.
          </p>
          <div className="space-y-2">
            <label className="block text-sm text-slate-300">E-mail Garmin</label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="twoj@email.com"
              className="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
              autoComplete="off"
            />
          </div>
          <div className="space-y-2">
            <label className="block text-sm text-slate-300">Hasło Garmin</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              className="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
              autoComplete="new-password"
            />
          </div>
          {connectError && (
            <div className="rounded-md border border-red-500/40 bg-red-900/40 px-3 py-2 text-sm text-red-100">
              {connectError}
            </div>
          )}
          <button
            onClick={handleConnect}
            disabled={connecting || !email.trim() || !password.trim()}
            className={`rounded-lg px-4 py-2 text-sm font-semibold text-white transition ${
              connecting || !email.trim() || !password.trim()
                ? 'bg-slate-600 cursor-not-allowed opacity-60'
                : 'bg-indigo-600 hover:bg-indigo-500'
            }`}
          >
            {connecting ? 'Łączenie...' : 'Połącz z Garmin'}
          </button>
        </div>
      )}

      {view === 'connected' && (
        <div className="space-y-5">
          <div className="flex items-center gap-2">
            <span className="inline-block h-2 w-2 rounded-full bg-emerald-400" />
            <span className="text-sm text-emerald-300 font-medium">
              Połączono{displayName ? ` — ${displayName}` : ''}
            </span>
            <button
              onClick={() => { setView('form'); setSyncResult(null); setSyncError(null) }}
              className="ml-4 text-xs text-slate-400 underline hover:text-slate-200"
            >
              Zmień konto
            </button>
          </div>

          <div className="flex flex-wrap items-end gap-4">
            <div className="space-y-1">
              <label className="block text-sm text-slate-300">Zakres synchronizacji</label>
              <select
                value={syncDays}
                onChange={(e) => setSyncDays(Number(e.target.value))}
                className="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
              >
                {SYNC_OPTIONS.map((o) => (
                  <option key={o.days} value={o.days}>{o.label}</option>
                ))}
              </select>
            </div>
            <div className="space-y-1">
              <label className="block text-sm text-slate-300">Typ aktywności</label>
              <select
                value={syncActivityType}
                onChange={(e) => setSyncActivityType(e.target.value)}
                className="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"
              >
                <option value="running">Bieganie</option>
                <option value="cycling">Kolarstwo</option>
                <option value="">Wszystkie</option>
              </select>
            </div>
            <button
              onClick={handleSync}
              disabled={syncing}
              className={`rounded-lg px-4 py-2 text-sm font-semibold text-white transition ${
                syncing
                  ? 'bg-slate-600 cursor-not-allowed opacity-60'
                  : 'bg-emerald-600 hover:bg-emerald-500'
              }`}
            >
              {syncing ? 'Synchronizuję...' : 'Synchronizuj aktywności'}
            </button>
          </div>

          {syncResult && (
            <div className="rounded-lg border border-emerald-500/30 bg-emerald-900/20 px-4 py-3 text-sm text-emerald-100 space-y-1">
              <div className="font-semibold">Synchronizacja zakończona</div>
              <div className="text-slate-300 text-xs space-x-4">
                <span>Pobrano: <strong>{syncResult.fetched}</strong></span>
                <span>Dodano: <strong>{syncResult.imported}</strong></span>
                <span>Duplikaty: <strong>{syncResult.deduped}</strong></span>
                {syncResult.failed > 0 && (
                  <span className="text-amber-300">Błędy: <strong>{syncResult.failed}</strong></span>
                )}
              </div>
            </div>
          )}

          {syncError && (
            <div className="rounded-md border border-red-500/40 bg-red-900/40 px-3 py-2 text-sm text-red-100">
              {syncError}
            </div>
          )}
        </div>
      )}
    </section>
  )
}
