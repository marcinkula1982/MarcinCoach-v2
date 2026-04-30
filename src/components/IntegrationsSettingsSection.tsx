import { useState, useEffect, useCallback } from 'react'
import {
  getIntegrationsStatus,
  disconnectIntegration,
  connectStrava,
  syncGarmin,
  syncStrava,
  type IntegrationStatus,
} from '../api/integrations'

type ProviderMeta = {
  key: string
  label: string
  description: string
  available: boolean
  fallbackLabel?: string
}

const PROVIDER_META: ProviderMeta[] = [
  {
    key: 'garmin',
    label: 'Garmin Connect',
    description: 'Synchronizuj historię treningów i wysyłaj plany na zegarek.',
    available: true,
  },
  {
    key: 'strava',
    label: 'Strava',
    description: 'Importuj aktywności z konta Strava przez OAuth.',
    available: true,
  },
  {
    key: 'polar',
    label: 'Polar',
    description: 'Integracja Polar AccessLink — w przygotowaniu.',
    available: false,
    fallbackLabel: 'Wgraj pliki TCX/GPX z Polar Flow',
  },
  {
    key: 'suunto',
    label: 'Suunto',
    description: 'Oficjalne Suunto API Zone — w przygotowaniu.',
    available: false,
    fallbackLabel: 'Wgraj pliki FIT/GPX z Suunto App',
  },
  {
    key: 'coros',
    label: 'Coros',
    description: 'Coros — brak publicznego API; fallback: import pliku FIT/TCX.',
    available: false,
    fallbackLabel: 'Wgraj pliki FIT/TCX z Coros App',
  },
]

function formatLastSync(iso: string | null): string {
  if (!iso) return 'Nigdy'
  try {
    const d = new Date(iso)
    return d.toLocaleString('pl-PL', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return 'Błąd daty'
  }
}

type CardProps = {
  meta: ProviderMeta
  status: IntegrationStatus | null
  onSync: () => Promise<void>
  onDisconnect: () => Promise<void>
  onConnect: () => Promise<void>
  busy: boolean
}

function IntegrationCard({ meta, status, onSync, onDisconnect, onConnect, busy }: CardProps) {
  const connected = status?.connected ?? false
  const lastSync = status?.lastSyncAt ?? null

  return (
    <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-5">
      <div className="flex items-start justify-between gap-2">
        <div>
          <p className="font-semibold text-white">{meta.label}</p>
          <p className="mt-0.5 text-sm text-slate-400">{meta.description}</p>
        </div>
        <span
          className={`mt-0.5 flex-shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${
            !meta.available
              ? 'bg-slate-700 text-slate-400'
              : connected
              ? 'bg-emerald-900/60 text-emerald-300'
              : 'bg-slate-700 text-slate-400'
          }`}
        >
          {!meta.available ? 'Wkrótce' : connected ? 'Połączono' : 'Niepołączono'}
        </span>
      </div>

      {meta.available && connected && (
        <p className="mt-2 text-xs text-slate-500">
          Ostatnia synchronizacja: {formatLastSync(lastSync)}
        </p>
      )}

      {meta.fallbackLabel && (
        <p className="mt-2 text-xs text-indigo-400">{meta.fallbackLabel}</p>
      )}

      {meta.available && (
        <div className="mt-4 flex flex-wrap gap-2">
          {connected ? (
            <>
              <button
                onClick={onSync}
                disabled={busy}
                className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
              >
                {busy ? 'Synchronizuję…' : 'Synchronizuj teraz'}
              </button>
              <button
                onClick={onDisconnect}
                disabled={busy}
                className="rounded-lg border border-rose-700 px-3 py-1.5 text-xs font-medium text-rose-400 hover:border-rose-500 hover:text-rose-300 disabled:opacity-50"
              >
                Odłącz
              </button>
            </>
          ) : (
            <button
              onClick={onConnect}
              disabled={busy}
              className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            >
              {busy ? 'Łączę…' : 'Połącz'}
            </button>
          )}
        </div>
      )}
    </div>
  )
}

type Props = {
  /** Called after a Garmin sync so the plan section can refresh */
  onGarminSyncComplete?: () => void
}

export default function IntegrationsSettingsSection({ onGarminSyncComplete }: Props) {
  const [statuses, setStatuses] = useState<IntegrationStatus[]>([])
  const [loading, setLoading] = useState(true)
  const [busyProvider, setBusyProvider] = useState<string | null>(null)
  const [msg, setMsg] = useState<{ type: 'ok' | 'err'; text: string } | null>(null)

  const showMsg = (type: 'ok' | 'err', text: string) => {
    setMsg({ type, text })
    setTimeout(() => setMsg(null), 5000)
  }

  const load = useCallback(async () => {
    try {
      const data = await getIntegrationsStatus()
      setStatuses(data.integrations)
    } catch {
      showMsg('err', 'Nie udało się załadować statusu integracji.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const statusFor = (key: string): IntegrationStatus | null =>
    statuses.find((s) => s.provider === key) ?? null

  const handleSync = async (key: string) => {
    setBusyProvider(key)
    try {
      if (key === 'garmin') {
        const result = await syncGarmin(30)
        showMsg('ok', `Garmin: zaimportowano ${result.imported}, pominiętych duplikatów ${result.deduped}.`)
        onGarminSyncComplete?.()
      } else if (key === 'strava') {
        const result = await syncStrava()
        showMsg('ok', `Strava: zaimportowano ${result.imported}, pominiętych duplikatów ${result.deduped}.`)
      }
      await load()
    } catch {
      showMsg('err', `Błąd synchronizacji ${key}.`)
    } finally {
      setBusyProvider(null)
    }
  }

  const handleDisconnect = async (key: string) => {
    if (!window.confirm(`Odłączyć ${key}? Zaimportowane treningi pozostaną w aplikacji.`)) return
    setBusyProvider(key)
    try {
      await disconnectIntegration(key)
      showMsg('ok', `${key} odłączone. Twoje treningi pozostają w MarcinCoach.`)
      await load()
    } catch {
      showMsg('err', `Błąd odłączania ${key}.`)
    } finally {
      setBusyProvider(null)
    }
  }

  const handleConnect = async (key: string) => {
    setBusyProvider(key)
    try {
      if (key === 'strava') {
        const result = await connectStrava()
        window.location.href = result.url
        return
      }
      showMsg('err', `Połączenie ${key} dostępne tylko przez onboarding lub konfigurację ręczną.`)
    } catch {
      showMsg('err', `Błąd połączenia ${key}.`)
    } finally {
      setBusyProvider(null)
    }
  }

  return (
    <div className="space-y-4">
      {msg && (
        <div
          className={`rounded-lg px-4 py-3 text-sm ${
            msg.type === 'ok'
              ? 'bg-emerald-900/40 text-emerald-300'
              : 'bg-rose-900/40 text-rose-300'
          }`}
        >
          {msg.text}
        </div>
      )}

      {loading && (
        <p className="text-center text-sm text-slate-500 py-8">Ładowanie statusu integracji…</p>
      )}

      {!loading &&
        PROVIDER_META.map((meta) => (
          <IntegrationCard
            key={meta.key}
            meta={meta}
            status={statusFor(meta.key)}
            onSync={() => handleSync(meta.key)}
            onDisconnect={() => handleDisconnect(meta.key)}
            onConnect={() => handleConnect(meta.key)}
            busy={busyProvider === meta.key}
          />
        ))}

      <p className="text-xs text-slate-600 pt-2">
        Garmin Connect używa nieoficjalnego połączenia — możliwe ograniczenia po stronie Garmina.
        Zaimportowane treningi zawsze pozostają w Twoim koncie MarcinCoach po odłączeniu integracji.
      </p>
    </div>
  )
}
