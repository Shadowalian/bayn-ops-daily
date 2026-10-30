import { useCallback, useEffect, useState } from 'react'
import { api } from '../api'
import { useAuth } from '../AuthContext'

type TeamResponse = Awaited<ReturnType<typeof api.teamToday>>

function shiftDate(iso: string, days: number) {
  const d = new Date(`${iso}T12:00:00`)
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

export default function TeamToday() {
  const { user } = useAuth()
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [data, setData] = useState<TeamResponse | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [unlockNote, setUnlockNote] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const res = await api.teamToday(date)
      setData(res)
      setError('')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load team')
    }
  }, [date])

  useEffect(() => {
    load()
    const id = setInterval(load, 120000)
    return () => clearInterval(id)
  }, [load])

  if (!data) return <div className="shell">{error || 'Loading team…'}</div>

  const selected = data.members.find((m) => m.user.id === selectedId)
  const attention = data.needs_attention
  const isToday = data.date === new Date().toISOString().slice(0, 10)

  async function unlock() {
    if (!selected?.day?.id || !unlockNote.trim()) return
    setBusy(true)
    try {
      await api.unlockDay(selected.day.id, unlockNote.trim())
      setUnlockNote('')
      await load()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unlock failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page">
      <header className="page-head">
        <div>
          <p className="eyebrow">Team · {data.timezone}</p>
          <h1>{isToday ? 'Today' : data.date}</h1>
        </div>
        <div className="row gap wrap">
          <button type="button" className="secondary small" onClick={() => setDate((d) => shiftDate(d, -1))}>
            ← Prev day
          </button>
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          <button type="button" className="secondary small" onClick={() => setDate((d) => shiftDate(d, 1))}>
            Next day →
          </button>
          <button
            type="button"
            className="secondary small"
            onClick={() => setDate(new Date().toISOString().slice(0, 10))}
          >
            Jump to today
          </button>
          <button className="secondary small" onClick={load}>
            Refresh
          </button>
        </div>
      </header>

      {error && <p className="error">{error}</p>}

      <section className="panel attention">
        <h2>Needs attention</h2>
        {attention.not_linked.length > 0 && (
          <p>
            <strong>Not linked to Telegram:</strong>{' '}
            {attention.not_linked.map((u) => `${u.name} (${u.employee_id})`).join(', ')}
          </p>
        )}
        {attention.missing_plans.length > 0 && (
          <p>
            <strong>No plan yet:</strong> {attention.missing_plans.map((u) => u.name).join(', ')}
          </p>
        )}
        {attention.not_closed.length > 0 && (
          <p>
            <strong>Day not closed:</strong> {attention.not_closed.map((u) => u.name).join(', ')}
          </p>
        )}
        {attention.open_blockers.length > 0 && (
          <p>
            <strong>Open blockers:</strong>{' '}
            {attention.open_blockers.map((b) => `${b.user.name}: ${b.blocker.title}`).join(' · ')}
          </p>
        )}
        {attention.not_linked.length === 0 &&
          attention.missing_plans.length === 0 &&
          attention.not_closed.length === 0 &&
          attention.open_blockers.length === 0 && <p className="muted">All clear for this day.</p>}
      </section>

      <section className="panel">
        <h2>People</h2>
        <div className="table-wrap">
          <table className="team-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>State</th>
                <th>Done</th>
                <th>Rate</th>
              </tr>
            </thead>
            <tbody>
              {data.members.map((m) => (
                <tr
                  key={m.user.id}
                  className={selectedId === m.user.id ? 'selected' : undefined}
                  onClick={() => setSelectedId(m.user.id)}
                  style={{ cursor: 'pointer' }}
                >
                  <td>
                    <code>{m.user.employee_id}</code>
                  </td>
                  <td>{m.user.name}</td>
                  <td>{m.day?.state || 'not_started'}</td>
                  <td>
                    {m.overall_done}/{m.overall_planned}
                  </td>
                  <td>{m.overall_completion == null ? '—' : `${m.overall_completion}%`}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {selected && (
        <section className="panel">
          <h2>
            {selected.user.name} · <code>{selected.user.employee_id}</code>
          </h2>
          {selected.tasks && selected.tasks.length > 0 ? (
            <ul className="task-list">
              {selected.tasks.map((t) => (
                <li key={t.id}>
                  <strong>{t.title}</strong> · {t.result}
                  {t.reason_note ? ` — ${t.reason_note}` : ''}
                </li>
              ))}
            </ul>
          ) : (
            <p className="muted">No tasks for this day.</p>
          )}
          {selected.day && user?.role === 'manager' && (
            <div className="row gap wrap" style={{ marginTop: '0.75rem' }}>
              <input
                placeholder="Unlock note"
                value={unlockNote}
                onChange={(e) => setUnlockNote(e.target.value)}
              />
              <button type="button" disabled={busy || !unlockNote.trim()} onClick={unlock}>
                Unlock plan edit
              </button>
            </div>
          )}
        </section>
      )}
    </div>
  )
}
