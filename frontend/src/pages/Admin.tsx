import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { api, REASON_LABELS, type Dashboard, type User } from '../api'
import { useAuth } from '../AuthContext'

type Tab = 'overview' | 'employees' | 'operations' | 'bot'

function pct(n: number | null | undefined) {
  return n == null ? '—' : `${n}%`
}

function todayMinus(days: number) {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return d.toISOString().slice(0, 10)
}

export default function Admin() {
  const { user } = useAuth()
  const [tab, setTab] = useState<Tab>('overview')
  const [from, setFrom] = useState(todayMinus(6))
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10))
  const [dash, setDash] = useState<Dashboard | null>(null)
  const [employees, setEmployees] = useState<User[]>([])
  const [morning, setMorning] = useState('09:00')
  const [evening, setEvening] = useState('17:30')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [unlockNotes, setUnlockNotes] = useState<Record<number, string>>({})

  const [form, setForm] = useState({
    employee_id: '',
    name: '',
    email: '',
    role: 'member' as 'member' | 'manager',
    password: '',
  })

  const loadDashboard = useCallback(async () => {
    try {
      const dashboard = await api.dashboard(from, to)
      setDash(dashboard)
      setError('')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load admin')
    }
  }, [from, to])

  const loadEmployees = useCallback(async () => {
    try {
      const emp = await api.listEmployees()
      setEmployees(emp.employees)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load employees')
    }
  }, [])

  const load = useCallback(async () => {
    await Promise.all([
      loadDashboard(),
      tab === 'employees' ? loadEmployees() : Promise.resolve(),
    ])
  }, [loadDashboard, loadEmployees, tab])

  useEffect(() => {
    if (user?.role !== 'manager') return
    api
      .getSettings()
      .then((settings) => {
        setMorning(settings.reminder_times?.morning || '09:00')
        setEvening(settings.reminder_times?.evening || '17:30')
      })
      .catch(() => {})
    loadDashboard()
    const id = setInterval(loadDashboard, 120000)
    return () => clearInterval(id)
  }, [user, loadDashboard])

  useEffect(() => {
    if (user?.role === 'manager' && tab === 'employees') loadEmployees()
  }, [user, tab, loadEmployees])

  useEffect(() => {
    if (user?.role !== 'manager' || tab !== 'employees') return
    api
      .nextEmployeeCode(form.role)
      .then((r) => setForm((f) => (f.employee_id ? f : { ...f, employee_id: r.employee_id })))
      .catch(() => {})
  }, [user, tab, form.role])

  if (user && user.role !== 'manager') return <Navigate to="/" replace />

  async function run(action: () => Promise<{ message?: string } | unknown>) {
    setBusy(true)
    setMessage('')
    setError('')
    try {
      const res = (await action()) as { message?: string }
      setMessage(res?.message || 'Saved')
      await load()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed')
    } finally {
      setBusy(false)
    }
  }

  async function suggestCode(role: 'member' | 'manager') {
    try {
      const r = await api.nextEmployeeCode(role)
      setForm((f) => ({ ...f, role, employee_id: r.employee_id }))
    } catch {
      setForm((f) => ({ ...f, role, employee_id: role === 'manager' ? 'MGR001' : 'EMP001' }))
    }
  }

  async function createEmployee(e: FormEvent) {
    e.preventDefault()
    await run(async () => {
      const created = await api.createEmployee({
        employee_id: form.employee_id.trim().toUpperCase(),
        name: form.name.trim(),
        email: form.email.trim() || undefined,
        role: form.role,
        password: form.password,
      })
      const next = await api.nextEmployeeCode(form.role).catch(() => ({ employee_id: '' }))
      setForm({
        employee_id: next.employee_id,
        name: '',
        email: '',
        role: form.role,
        password: '',
      })
      return { message: `Assigned ${created.employee_id} to ${created.name}` }
    })
  }

  const sortedEmployees = useMemo(
    () => [...(dash?.employees || [])].sort((a, b) => (b.period.completion ?? -1) - (a.period.completion ?? -1)),
    [dash]
  )

  return (
    <div className="page admin-page">
      <header className="page-head">
        <div>
          <p className="eyebrow">Operations control</p>
          <h1>Admin dashboard</h1>
          <p className="muted">
            {dash ? `${dash.today} · ${dash.timezone}` : 'Loading…'} · signed in as {user?.employee_id}
          </p>
        </div>
        <div className="row gap wrap">
          <label className="inline">
            From
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </label>
          <label className="inline">
            To
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </label>
          <button className="secondary small" disabled={busy} onClick={() => load()}>
            Refresh
          </button>
        </div>
      </header>

      {error && <p className="error">{error}</p>}
      {message && <p className="hint">{message}</p>}

      <div className="admin-tabs">
        {(
          [
            ['overview', 'Overview'],
            ['employees', 'Employees'],
            ['operations', 'Today ops'],
            ['bot', 'Bot & times'],
          ] as Array<[Tab, string]>
        ).map(([id, label]) => (
          <button key={id} type="button" className={tab === id ? '' : 'secondary'} onClick={() => setTab(id)}>
            {label}
          </button>
        ))}
      </div>

      {tab === 'overview' && dash && (
        <>
          <section className="kpi-grid">
            <Kpi label="Team work rate (today)" value={pct(dash.kpis.team_completion_today)} hint={`${dash.kpis.tasks_done_today}/${dash.kpis.tasks_planned_today} tasks`} />
            <Kpi label="Work rate (period)" value={pct(dash.kpis.team_completion_period)} hint={`${dash.kpis.tasks_done_period}/${dash.kpis.tasks_planned_period} tasks`} />
            <Kpi label="Plans in today" value={`${dash.kpis.plans_submitted_today}/${dash.kpis.active_members}`} hint="Members with locked plan" />
            <Kpi label="Days closed" value={`${dash.kpis.days_closed_today}/${dash.kpis.active_members}`} hint="Evening complete" />
            <Kpi label="Open blockers" value={String(dash.kpis.open_blockers_today)} hint="Needs unblock" />
            <Kpi label="Telegram linked" value={`${dash.kpis.telegram_linked}/${dash.kpis.active_members}`} hint={`${dash.kpis.telegram_link_rate}% linked`} />
          </section>

          <section className="panel">
            <div className="row between">
              <h2>Employee work rates</h2>
              <div className="row gap">
                <button
                  className="secondary small"
                  disabled={busy}
                  onClick={() => run(async () => { await api.exportCsv('tasks', from, to); return { message: 'Tasks CSV downloaded' } })}
                >
                  Export tasks
                </button>
                <button
                  className="secondary small"
                  disabled={busy}
                  onClick={() => run(async () => { await api.exportCsv('reasons', from, to); return { message: 'Reasons CSV downloaded' } })}
                >
                  Export reasons
                </button>
              </div>
            </div>
            <div className="table-wrap">
              <table className="team-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Today</th>
                    <th>Today %</th>
                    <th>Period %</th>
                    <th>Plan rate</th>
                    <th>Close rate</th>
                    <th>Hot carry</th>
                    <th>TG</th>
                  </tr>
                </thead>
                <tbody>
                  {sortedEmployees.map((row) => (
                    <tr key={row.user.id}>
                      <td><code>{row.user.employee_id}</code></td>
                      <td>{row.user.name}</td>
                      <td>
                        <span className={`pill state-${row.today.state}`}>{row.today.state.replace('_', ' ')}</span>
                      </td>
                      <td>{row.today.done}/{row.today.planned} ({pct(row.today.completion)})</td>
                      <td><strong>{pct(row.period.completion)}</strong> · {row.period.done}/{row.period.planned}</td>
                      <td>{pct(row.period.plan_submission_rate)}</td>
                      <td>{pct(row.period.close_rate)}</td>
                      <td>{row.period.carry_over_hot || '—'}</td>
                      <td>{row.user.telegram_linked ? 'Yes' : 'No'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <div className="admin-split">
            <section className="panel">
              <h2>Daily completion trend</h2>
              <ul className="trend-list">
                {dash.daily_trend.map((d) => (
                  <li key={d.date}>
                    <span>{d.date}</span>
                    <div className="trend-bar-wrap">
                      <div className="trend-bar" style={{ width: `${d.completion ?? 0}%` }} />
                    </div>
                    <strong>{pct(d.completion)}</strong>
                    <span className="muted">{d.done}/{d.planned}</span>
                  </li>
                ))}
              </ul>
            </section>

            <section className="panel">
              <h2>Why work slips</h2>
              {dash.reason_breakdown.length === 0 && <p className="muted">No not-done reasons in this period.</p>}
              <ul className="simple-list">
                {dash.reason_breakdown.map((r) => (
                  <li key={r.reason_code}>
                    <strong>{REASON_LABELS[r.reason_code] || r.reason_code}</strong>
                    <span className="muted"> · {r.count}</span>
                  </li>
                ))}
              </ul>
            </section>
          </div>

          <section className="panel attention">
            <h2>Needs attention</h2>
            <p><strong>Missing plans:</strong> {dash.needs_attention.missing_plans.map((u) => `${u.name} (${u.employee_id})`).join(', ') || 'None'}</p>
            <p><strong>Not closed:</strong> {dash.needs_attention.not_closed.map((u) => `${u.name} (${u.employee_id})`).join(', ') || 'None'}</p>
            <p><strong>Not on Telegram:</strong> {dash.needs_attention.not_linked.map((u) => `${u.name} (${u.employee_id})`).join(', ') || 'None'}</p>
            {dash.needs_attention.roadblocks.length > 0 && (
              <ul className="simple-list">
                {dash.needs_attention.roadblocks.map((b) => (
                  <li key={b.task_id}>
                    {b.name} ({b.employee_id}): {b.title} — {b.blocker_note || 'no note'}
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}

      {tab === 'employees' && (
        <>
          <section className="panel">
            <h2>Create employee code &amp; assign person</h2>
            <p className="muted">
              Only admins use this web app. Create <code>EMP###</code> / <code>MGR###</code> codes here, assign people,
              then they use Telegram only: tap <b>Start</b> → enter their code → submit daily tasks.
            </p>
            <form className="employee-form" onSubmit={createEmployee}>
              <select
                value={form.role}
                onChange={(e) => suggestCode(e.target.value as 'member' | 'manager')}
                aria-label="Role / code prefix"
              >
                <option value="member">Member (EMP)</option>
                <option value="manager">Manager (MGR)</option>
              </select>
              <input
                required
                placeholder="Code e.g. EMP005"
                value={form.employee_id}
                onChange={(e) => setForm({ ...form, employee_id: e.target.value.toUpperCase() })}
              />
              <input required placeholder="Full name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
              <input placeholder="Email (optional)" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
              <input required type="password" placeholder="Temp password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} minLength={6} />
              <button type="submit" disabled={busy}>Assign code</button>
            </form>
          </section>

          <section className="panel">
            <h2>Directory ({employees.length})</h2>
            <div className="table-wrap">
              <table className="team-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Active</th>
                    <th>Telegram</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {employees.map((emp) => (
                    <tr key={emp.id}>
                      <td><code>{emp.employee_id}</code></td>
                      <td>{emp.name}</td>
                      <td>{emp.role}</td>
                      <td>{emp.active ? 'Yes' : 'No'}</td>
                      <td>{emp.telegram_linked ? 'Linked' : '—'}</td>
                      <td>
                        <div className="row gap wrap">
                          <button
                            type="button"
                            className="secondary small"
                            disabled={busy}
                            onClick={() => {
                              const pw = window.prompt(`New password for ${emp.employee_id}`)
                              if (!pw) return
                              run(async () => {
                                await api.updateEmployee(emp.id, { password: pw })
                                return { message: `Password updated for ${emp.employee_id}` }
                              })
                            }}
                          >
                            Reset PW
                          </button>
                          {emp.telegram_linked && (
                            <button
                              type="button"
                              className="secondary small"
                              disabled={busy}
                              onClick={() =>
                                run(async () => {
                                  await api.updateEmployee(emp.id, { clear_telegram: true })
                                  return { message: 'Telegram unlinked' }
                                })
                              }
                            >
                              Unlink TG
                            </button>
                          )}
                          {emp.active && emp.id !== user?.id && (
                            <button
                              type="button"
                              className="secondary small"
                              disabled={busy}
                              onClick={() =>
                                run(async () => {
                                  await api.deactivateEmployee(emp.id)
                                  return { message: `${emp.employee_id} deactivated` }
                                })
                              }
                            >
                              Deactivate
                            </button>
                          )}
                          {!emp.active && (
                            <button
                              type="button"
                              className="secondary small"
                              disabled={busy}
                              onClick={() =>
                                run(async () => {
                                  await api.updateEmployee(emp.id, { active: true })
                                  return { message: `${emp.employee_id} reactivated` }
                                })
                              }
                            >
                              Reactivate
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </>
      )}

      {tab === 'operations' && dash && (
        <section className="panel">
          <h2>Today — unlock & status</h2>
          <div className="table-wrap">
            <table className="team-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>State</th>
                  <th>Done</th>
                  <th>Blockers</th>
                  <th>Unlock plan edit</th>
                </tr>
              </thead>
              <tbody>
                {dash.employees.map((row) => (
                  <tr key={row.user.id}>
                    <td><code>{row.user.employee_id}</code></td>
                    <td>{row.user.name}</td>
                    <td><span className={`pill state-${row.today.state}`}>{row.today.state.replace('_', ' ')}</span></td>
                    <td>{row.today.done}/{row.today.planned}</td>
                    <td>{row.today.blockers || '—'}</td>
                    <td>
                      {row.today.day_id && row.today.state !== 'closed' ? (
                        <div className="row gap wrap">
                          <input
                            placeholder="Required note"
                            value={unlockNotes[row.today.day_id] || ''}
                            onChange={(e) => setUnlockNotes({ ...unlockNotes, [row.today.day_id!]: e.target.value })}
                          />
                          <button
                            type="button"
                            className="small"
                            disabled={busy || !(unlockNotes[row.today.day_id] || '').trim()}
                            onClick={() =>
                              run(async () => {
                                await api.unlockDay(row.today.day_id!, unlockNotes[row.today.day_id!].trim())
                                return { message: `Unlocked ${row.user.employee_id}` }
                              })
                            }
                          >
                            Unlock
                          </button>
                        </div>
                      ) : (
                        '—'
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {tab === 'bot' && (
        <>
          <section className="panel">
            <h2>Telegram prompts</h2>
            <p className="muted">Members link with <code>/start EMP001</code> (their employee ID).</p>
            <div className="row gap wrap">
              <button disabled={busy} onClick={() => run(() => api.sendMorning())}>Send morning now</button>
              <button className="secondary" disabled={busy} onClick={() => run(() => api.sendEvening())}>Send evening now</button>
            </div>
          </section>
          <section className="panel">
            <h2>Automatic times (Africa/Addis_Ababa)</h2>
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
        </>
      )}
    </div>
  )
}

function Kpi({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="kpi-card">
      <p className="kpi-label">{label}</p>
      <p className="kpi-value">{value}</p>
      {hint && <p className="kpi-hint">{hint}</p>}
    </div>
  )
}
