import { useCallback, useEffect, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../AuthContext'

export default function Admin() {
  const { user } = useAuth()
  const [status, setStatus] = useState<Awaited<ReturnType<typeof api.botStatus>> | null>(null)
  const [morning, setMorning] = useState('09:00')
  const [evening, setEvening] = useState('17:30')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const [bot, settings] = await Promise.all([api.botStatus(), api.getSettings()])
      setStatus(bot)
      setMorning(settings.reminder_times?.morning || '09:00')
      setEvening(settings.reminder_times?.evening || '17:30')
      setError('')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load admin')
    }
  }, [])

  useEffect(() => {
    if (user?.role === 'manager') load()
  }, [user, load])

  if (user && user.role !== 'manager') return <Navigate to="/" replace />

  async function run(action: () => Promise<{ message?: string } | unknown>) {
    setBusy(true)
    setMessage('')
    setError('')
    try {
      const res = (await action()) as { message?: string }
      setMessage(res?.message || 'Done')
      await load()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page">
      <header className="page-head">
        <div>
          <p className="eyebrow">Admin</p>
          <h1>Telegram daily flow</h1>
        </div>
      </header>

      {error && <p className="error">{error}</p>}
      {message && <p className="hint">{message}</p>}

      <section className="panel">
        <h2>Bot</h2>
        <p className="muted">
          Token configured: {status?.bot_token_set ? 'Yes' : 'No — set TELEGRAM_BOT_TOKEN in backend .env'}
        </p>
        <p className="muted">
          Members link with <code>/start their@email.com</code> in the bot chat.
        </p>
        <p className="muted">
          Local polling: <code>php artisan telegram:poll</code> · Scheduler: <code>php artisan schedule:work</code>
        </p>
        <div className="row gap wrap" style={{ marginTop: '0.75rem' }}>
          <button disabled={busy} onClick={() => run(() => api.sendMorning())}>
            Send morning now
          </button>
          <button className="secondary" disabled={busy} onClick={() => run(() => api.sendEvening())}>
            Send evening now
          </button>
        </div>
      </section>

      <section className="panel">
        <h2>Automatic times (Addis Ababa)</h2>
        <div className="row gap wrap">
          <label className="inline">
            Morning
            <input type="time" value={morning} onChange={(e) => setMorning(e.target.value)} />
          </label>
          <label className="inline">
            Evening
            <input type="time" value={evening} onChange={(e) => setEvening(e.target.value)} />
          </label>
          <button
            disabled={busy}
            onClick={() =>
              run(async () => {
                await api.updateSettings({ reminder_times: { morning, evening } })
                return { message: 'Times saved' }
              })
            }
          >
            Save times
          </button>
        </div>
      </section>

      <section className="panel">
        <h2>Members today</h2>
        <ul className="task-list">
          {(status?.members || []).map((m) => (
            <li key={m.user.id} className="task-card">
              <div className="row between">
                <strong>{m.user.name}</strong>
                <span className={`pill state-${m.state}`}>{m.state.replace('_', ' ')}</span>
              </div>
              <p className="muted">
                Telegram: {m.telegram_linked ? 'linked' : 'not linked'} · Plan {m.done}/{m.planned}
                {m.edit_unlocked ? ' · edit unlocked' : ''}
              </p>
            </li>
          ))}
        </ul>
      </section>
    </div>
  )
}
