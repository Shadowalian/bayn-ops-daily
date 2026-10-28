import { useEffect, useState } from 'react'
import { api } from '../api'
import { useAuth } from '../AuthContext'

type DayRow = {
  id: number
  date: string
  work_status: string
  check_in_at: string | null
  check_out_at: string | null
  closed_at: string | null
  tasks: Array<{ id: number; title: string; tier: string; result: string }>
}

export default function History() {
  const { user } = useAuth()
  const [days, setDays] = useState<DayRow[]>([])
  const [error, setError] = useState('')

  useEffect(() => {
    if (!user) return
    api
      .history(user.id)
      .then((json) => setDays(json.data || []))
      .catch((err) => setError(err instanceof Error ? err.message : 'Failed to load history'))
  }, [user])

  return (
    <div className="page">
      <header className="page-head">
        <div>
          <p className="eyebrow">History</p>
          <h1>My previous days</h1>
        </div>
      </header>
      {error && <p className="error">{error}</p>}
      <section className="panel">
        {days.length === 0 && <p className="muted">No history yet.</p>}
        <ul className="task-list">
          {days.map((d) => (
            <li key={d.id} className="task-card">
              <div className="row between">
                <strong>{d.date}</strong>
                <span className="muted">{d.work_status}</span>
              </div>
              <p className="muted">
                {d.tasks.filter((t) => t.result === 'done').length}/{d.tasks.length} done
                {d.closed_at ? ' · closed' : ' · open'}
              </p>
            </li>
          ))}
        </ul>
      </section>
    </div>
  )
}
