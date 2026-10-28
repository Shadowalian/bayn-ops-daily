import { useCallback, useEffect, useState } from 'react'
import { api } from '../api'
import { useAuth } from '../AuthContext'

type TeamResponse = Awaited<ReturnType<typeof api.teamToday>>

export default function TeamToday() {
  const { user } = useAuth()
  const [data, setData] = useState<TeamResponse | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [unlockNote, setUnlockNote] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const res = await api.teamToday()
      setData(res)
      setError('')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load team')
    }
  }, [])

  useEffect(() => {
    load()
    const id = setInterval(load, 120000)
    return () => clearInterval(id)
  }, [load])

  if (!data) return <div className="shell">{error || 'Loading team…'}</div>

  const selected = data.members.find((m) => m.user.id === selectedId)
  const attention = data.needs_attention

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
          <p className="eyebrow">Team · {data.date}</p>
          <h1>Today</h1>
        </div>
        <button className="secondary small" onClick={load}>
          Refresh
        </button>
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
            <strong>Roadblocks:</strong>{' '}
            {attention.open_blockers.map((b) => `${b.user.name}: ${b.blocker.title}`).join(' · ')}
          </p>
        )}
      </section>

      <section className="panel">
        <div className="table-wrap">
          <table className="team-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Member</th>
                <th>Done</th>
                <th>Telegram</th>
                <th>Day</th>
              </tr>
            </thead>
            <tbody>
              {data.members.map((row) => (
                <tr
                  key={row.user.id}
                  className={selectedId === row.user.id ? 'selected' : ''}
                  onClick={() => setSelectedId(row.user.id)}
                >
                  <td><code>{row.user.employee_id}</code></td>
                  <td>
                    <strong>{row.user.name}</strong>
                  </td>
                  <td>
                    {row.overall_done}/{row.overall_planned}
                    {row.overall_completion != null ? ` (${row.overall_completion}%)` : ''}
                  </td>
                  <td>{row.user.telegram_chat_id ? 'Linked' : '—'}</td>
                  <td>
                    <span className={`pill state-${row.day?.state || 'not_started'}`}>
                      {(row.day?.state || 'not_started').replace('_', ' ')}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {selected && (
        <section className="panel">
          <h2>{selected.user.name}'s plan</h2>
          {(selected.tasks?.length ?? 0) === 0 && <p className="muted">No tasks yet.</p>}
          <ul className="task-list">
            {(selected.tasks ?? []).map((t) => (
              <li key={t.id} className="task-card">
                <div className="row between">
                  <strong>
                    {t.position}) {t.title}
                  </strong>
                  <span>{t.result}</span>
                </div>
                {t.reason_note && <p className="muted">Roadblock: {t.reason_note}</p>}
              </li>
            ))}
          </ul>

          {user?.role === 'manager' && selected.day && selected.day.state !== 'closed' && (
            <div style={{ marginTop: '1rem' }}>
              <h3>Unlock plan edit</h3>
              <p className="muted">Member asked to change their locked morning plan.</p>
              <input
                placeholder="Note (required)"
                value={unlockNote}
                onChange={(e) => setUnlockNote(e.target.value)}
              />
              <button disabled={busy || !unlockNote.trim()} onClick={unlock} style={{ marginTop: '0.5rem' }}>
                Allow edit + re-prompt on Telegram
              </button>
            </div>
          )}
        </section>
      )}
    </div>
  )
}
