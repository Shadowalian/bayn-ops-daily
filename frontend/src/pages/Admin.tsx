import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { BarChart, DonutChart, Gauge } from '../components/Charts'
import { api, REASON_LABELS, type CodeSeries, type Dashboard, type TimeWindow, type User } from '../api'
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

const emptyWindow = (from: string, to: string): TimeWindow => ({ from, to })

export default function Admin() {
  const { user } = useAuth()
  const [tab, setTab] = useState<Tab>('overview')
  const [from, setFrom] = useState(todayMinus(6))
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10))
  const [dash, setDash] = useState<Dashboard | null>(null)
  const [employees, setEmployees] = useState<User[]>([])
  const [series, setSeries] = useState<CodeSeries[]>([])
  const [morning, setMorning] = useState<TimeWindow>(emptyWindow('08:30', '09:30'))
  const [evening, setEvening] = useState<TimeWindow>(emptyWindow('17:00', '18:00'))
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [unlockNotes, setUnlockNotes] = useState<Record<number, string>>({})

  const [form, setForm] = useState({
    code_series_id: '',
    employee_id: '',
    name: '',
    email: '',
    job_title: '',
    password: '',
  })
  const [editingId, setEditingId] = useState<number | null>(null)
  const [assign, setAssign] = useState({ user_id: '', title: '' })
  const [seriesForm, setSeriesForm] = useState({
    prefix: '',
    name: '',
    access_role: 'member' as 'member' | 'manager',
    next_number: '1',
  })
  const [editingSeriesId, setEditingSeriesId] = useState<number | null>(null)

  function resetSeriesForm() {
    setEditingSeriesId(null)
    setSeriesForm({ prefix: '', name: '', access_role: 'member', next_number: '1' })
  }

  function previewNextCode(prefix: string, nextNumber = 1, pad = 3) {
    const clean = prefix.trim().toUpperCase().replace(/[^A-Z0-9]/g, '')
    if (!clean) return '…'
    return `${clean}${String(nextNumber).padStart(pad, '0')}`
  }

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
    const emp = await api.listEmployees()
    setEmployees(emp.employees)
  }, [])

  const loadSeries = useCallback(async () => {
    const res = await api.listCodeSeries()
    setSeries(res.series)
  }, [])

  const load = useCallback(async () => {
    await Promise.all([loadDashboard(), loadEmployees(), loadSeries()])
  }, [loadDashboard, loadEmployees, loadSeries])

  useEffect(() => {
    if (user?.role !== 'manager') return
    api
      .getSettings()
      .then((settings) => {
        setMorning(settings.reminder_times?.morning || emptyWindow('08:30', '09:30'))
        setEvening(settings.reminder_times?.evening || emptyWindow('17:00', '18:00'))
      })
      .catch(() => {})
    load()
    const id = setInterval(loadDashboard, 120000)
    return () => clearInterval(id)
  }, [user, load, loadDashboard])

  useEffect(() => {
    if (!series.length || form.code_series_id || editingId) return
    const first = series.find((s) => s.active && s.access_role === 'member') || series.find((s) => s.active)
    if (first) setForm((f) => ({ ...f, code_series_id: String(first.id), employee_id: first.next_code }))
  }, [series, form.code_series_id, editingId])

  useEffect(() => {
    if (!form.code_series_id || editingId) return
    api
      .nextEmployeeCode({ series_id: Number(form.code_series_id) })
      .then((r) => setForm((f) => ({ ...f, employee_id: r.employee_id })))
      .catch(() => {})
  }, [form.code_series_id, editingId])

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

  function resetPersonForm() {
    setEditingId(null)
    const first = series.find((s) => s.active && s.access_role === 'member') || series.find((s) => s.active)
    setForm({
      code_series_id: first ? String(first.id) : '',
      employee_id: first?.next_code || '',
      name: '',
      email: '',
      job_title: '',
      password: '',
    })
  }

  function startEdit(emp: User) {
    setEditingId(emp.id)
    setForm({
      code_series_id: emp.code_series_id ? String(emp.code_series_id) : '',
      employee_id: emp.employee_id,
      name: emp.name,
      email: emp.email || '',
      job_title: emp.job_title || '',
      password: '',
    })
    setTab('employees')
  }

  async function savePerson(e: FormEvent) {
    e.preventDefault()
    await run(async () => {
      if (editingId) {
        await api.updateEmployee(editingId, {
          employee_id: form.employee_id.trim().toUpperCase(),
          name: form.name.trim(),
          email: form.email.trim() || null,
          job_title: form.job_title.trim() || null,
          code_series_id: form.code_series_id ? Number(form.code_series_id) : null,
          ...(form.password ? { password: form.password } : {}),
        })
        resetPersonForm()
        return { message: 'Employee updated' }
      }
      if (!form.code_series_id) throw new Error('Pick a code series first')
      const created = await api.createEmployee({
        code_series_id: Number(form.code_series_id),
        employee_id: form.employee_id.trim().toUpperCase() || undefined,
        name: form.name.trim(),
        email: form.email.trim() || undefined,
        job_title: form.job_title.trim() || undefined,
        password: form.password,
      })
      resetPersonForm()
      return { message: `Created ${created.employee_id} · ${created.name}` }
    })
  }

  async function saveSeries(e: FormEvent) {
    e.preventDefault()
    const prefix = seriesForm.prefix.trim().toUpperCase()
    await run(async () => {
      if (editingSeriesId) {
        await api.updateCodeSeries(editingSeriesId, {
          prefix,
          name: seriesForm.name.trim() || prefix,
          access_role: seriesForm.access_role,
          next_number: Number(seriesForm.next_number) || 1,
        })
        resetSeriesForm()
        return { message: 'Code series updated' }
      }
      const created = await api.createCodeSeries({
        prefix,
        name: prefix,
        access_role: prefix.startsWith('MGR') ? 'manager' : 'member',
      })
      resetSeriesForm()
      return { message: `Series ${created.prefix} ready — next code ${created.next_code}` }
    })
  }

  async function assignWork(e: FormEvent) {
    e.preventDefault()
    if (!assign.user_id || !assign.title.trim()) return
    await run(async () => {
      const res = await api.assignTask({ user_id: Number(assign.user_id), title: assign.title.trim() })
      setAssign({ user_id: assign.user_id, title: '' })
      return { message: res.message }
    })
  }

  const activeWorkers = useMemo(() => employees.filter((e) => e.active && e.role === 'member'), [employees])
  const sortedEmployees = useMemo(
    () => [...(dash?.employees || [])].sort((a, b) => (b.period.completion ?? -1) - (a.period.completion ?? -1)),
    [dash]
  )
  const reasonSlices = useMemo(
    () =>
      (dash?.reason_breakdown || []).map((r) => ({
        label: REASON_LABELS[r.reason_code] || r.reason_code,
        value: r.count,
      })),
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
            ['employees', 'People & codes'],
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
            <h2>Performance graphs</h2>
            <p className="muted">How the team is executing across the selected period.</p>
            <div className="charts-grid">
              <div>
                <h3 className="chart-title">Daily completion %</h3>
                <BarChart
                  data={dash.daily_trend.map((d) => ({
                    label: d.date,
                    value: d.completion,
                    hint: `${d.done}/${d.planned}`,
                  }))}
                />
              </div>
              <div className="gauge-row">
                <Gauge value={dash.kpis.team_completion_today} label="Today work rate" />
                <Gauge value={dash.kpis.team_completion_period} label="Period work rate" />
              </div>
              <div>
                <h3 className="chart-title">Why work slips</h3>
                {reasonSlices.length === 0 ? (
                  <p className="muted">No not-done reasons in this period.</p>
                ) : (
                  <DonutChart slices={reasonSlices} />
                )}
              </div>
            </div>
          </section>

          <section className="panel">
            <h2>Assign work</h2>
            <p className="muted">Adds a task to the employee’s day alongside tasks they submit on Telegram.</p>
            <form className="employee-form" onSubmit={assignWork}>
              <select required value={assign.user_id} onChange={(e) => setAssign({ ...assign, user_id: e.target.value })}>
                <option value="">Select employee</option>
                {activeWorkers.map((e) => (
                  <option key={e.id} value={e.id}>
                    {e.employee_id} · {e.name}
                    {e.job_title ? ` (${e.job_title})` : ''}
                  </option>
                ))}
              </select>
              <input
                required
                placeholder="Task to assign"
                value={assign.title}
                onChange={(e) => setAssign({ ...assign, title: e.target.value })}
                maxLength={200}
              />
              <button type="submit" disabled={busy || !assign.user_id || !assign.title.trim()}>
                Assign
              </button>
            </form>
          </section>

          <section className="panel">
            <div className="row between">
              <h2>Employee work rates</h2>
              <div className="row gap">
                <button
                  className="secondary small"
                  disabled={busy}
                  onClick={() =>
                    run(async () => {
                      await api.exportCsv('tasks', from, to)
                      return { message: 'Tasks CSV downloaded' }
                    })
                  }
                >
                  Export tasks
                </button>
                <button
                  className="secondary small"
                  disabled={busy}
                  onClick={() =>
                    run(async () => {
                      await api.exportCsv('reasons', from, to)
                      return { message: 'Reasons CSV downloaded' }
                    })
                  }
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
                      <td>
                        <code>{row.user.employee_id}</code>
                      </td>
                      <td>{row.user.name}</td>
                      <td>
                        <span className={`pill state-${row.today.state}`}>{row.today.state.replace('_', ' ')}</span>
                      </td>
                      <td>
                        {row.today.done}/{row.today.planned} ({pct(row.today.completion)})
                      </td>
                      <td>
                        <strong>{pct(row.period.completion)}</strong> · {row.period.done}/{row.period.planned}
                      </td>
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

          <section className="panel attention">
            <h2>Needs attention</h2>
            <p>
              <strong>Missing plans:</strong>{' '}
              {dash.needs_attention.missing_plans.map((u) => `${u.name} (${u.employee_id})`).join(', ') || 'None'}
            </p>
            <p>
              <strong>Not closed:</strong>{' '}
              {dash.needs_attention.not_closed.map((u) => `${u.name} (${u.employee_id})`).join(', ') || 'None'}
            </p>
            <p>
              <strong>Not on Telegram:</strong>{' '}
              {dash.needs_attention.not_linked.map((u) => `${u.name} (${u.employee_id})`).join(', ') || 'None'}
            </p>
          </section>
        </>
      )}

      {tab === 'employees' && (
        <>
          <section className="panel">
            <h2>{editingSeriesId ? 'Edit code series' : 'Create code series'}</h2>
            <p className="muted">
              Type only the prefix (e.g. <code>EMP</code>, <code>SALES</code>). Numbers are assigned automatically —
              first person gets <code>{previewNextCode(seriesForm.prefix || 'EMP')}</code>, then the next, and so on.
              Prefixes starting with <code>MGR</code> become admin web access.
            </p>
            <form className="employee-form" onSubmit={saveSeries}>
              <input
                required
                placeholder="Prefix e.g. EMP"
                value={seriesForm.prefix}
                onChange={(e) => setSeriesForm({ ...seriesForm, prefix: e.target.value.toUpperCase() })}
              />
              {editingSeriesId ? (
                <>
                  <input
                    placeholder="Display name (optional)"
                    value={seriesForm.name}
                    onChange={(e) => setSeriesForm({ ...seriesForm, name: e.target.value })}
                  />
                  <select
                    value={seriesForm.access_role}
                    onChange={(e) =>
                      setSeriesForm({ ...seriesForm, access_role: e.target.value as 'member' | 'manager' })
                    }
                  >
                    <option value="member">Employee (Telegram)</option>
                    <option value="manager">Admin (web)</option>
                  </select>
                  <input
                    type="number"
                    min={1}
                    title="Next number in the sequence"
                    placeholder="Next number"
                    value={seriesForm.next_number}
                    onChange={(e) => setSeriesForm({ ...seriesForm, next_number: e.target.value })}
                  />
                </>
              ) : (
                <p className="muted" style={{ margin: 0, alignSelf: 'center' }}>
                  Preview: <code>{previewNextCode(seriesForm.prefix)}</code>
                </p>
              )}
              <button type="submit" disabled={busy}>
                {editingSeriesId ? 'Save series' : 'Create series'}
              </button>
              {editingSeriesId && (
                <button type="button" className="secondary" onClick={resetSeriesForm}>
                  Cancel
                </button>
              )}
            </form>

            <div className="table-wrap" style={{ marginTop: '1rem' }}>
              <table className="team-table">
                <thead>
                  <tr>
                    <th>Prefix</th>
                    <th>Name</th>
                    <th>Access</th>
                    <th>Next code</th>
                    <th>People</th>
                    <th>Active</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {series.map((s) => (
                    <tr key={s.id}>
                      <td>
                        <code>{s.prefix}</code>
                      </td>
                      <td>{s.name}</td>
                      <td>{s.access_role === 'manager' ? 'Admin' : 'Employee'}</td>
                      <td>
                        <code>{s.next_code}</code>
                      </td>
                      <td>{s.users_count}</td>
                      <td>{s.active ? 'Yes' : 'No'}</td>
                      <td>
                        <div className="row gap wrap">
                          <button
                            type="button"
                            className="secondary small"
                            disabled={busy}
                            onClick={() => {
                              setEditingSeriesId(s.id)
                              setSeriesForm({
                                prefix: s.prefix,
                                name: s.name,
                                access_role: s.access_role,
                                next_number: String(s.next_number),
                              })
                            }}
                          >
                            Edit
                          </button>
                          <button
                            type="button"
                            className="secondary small"
                            disabled={busy}
                            onClick={() =>
                              run(async () => {
                                await api.updateCodeSeries(s.id, { active: !s.active })
                                return { message: `${s.prefix} ${s.active ? 'disabled' : 'enabled'}` }
                              })
                            }
                          >
                            {s.active ? 'Disable' : 'Enable'}
                          </button>
                          <button
                            type="button"
                            className="secondary small"
                            disabled={busy || s.users_count > 0}
                            onClick={() => {
                              if (!window.confirm(`Delete series ${s.prefix}?`)) return
                              run(async () => {
                                await api.deleteCodeSeries(s.id)
                                return { message: `${s.prefix} deleted` }
                              })
                            }}
                          >
                            Delete
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <section className="panel">
            <h2>{editingId ? 'Edit person / code' : 'Assign person to a series'}</h2>
            <p className="muted">Pick a series — the next code is filled automatically. You can still edit the code.</p>
            <form className="employee-form" onSubmit={savePerson}>
              <select
                required={!editingId}
                value={form.code_series_id}
                onChange={(e) => setForm({ ...form, code_series_id: e.target.value })}
              >
                <option value="">Select code series</option>
                {series
                  .filter((s) => s.active || String(s.id) === form.code_series_id)
                  .map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.prefix} — {s.name} (next {s.next_code})
                    </option>
                  ))}
              </select>
              <input
                required
                placeholder="Code"
                value={form.employee_id}
                onChange={(e) => setForm({ ...form, employee_id: e.target.value.toUpperCase() })}
              />
              <input required placeholder="Full name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
              <input
                placeholder="Job title (Sales, Dev…)"
                value={form.job_title}
                onChange={(e) => setForm({ ...form, job_title: e.target.value })}
              />
              <input placeholder="Email (optional)" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
              <input
                required={!editingId}
                type="password"
                placeholder={editingId ? 'New password (optional)' : 'Temp password'}
                value={form.password}
                onChange={(e) => setForm({ ...form, password: e.target.value })}
                minLength={editingId ? undefined : 6}
              />
              <button type="submit" disabled={busy}>
                {editingId ? 'Save person' : 'Create person'}
              </button>
              {editingId && (
                <button type="button" className="secondary" disabled={busy} onClick={resetPersonForm}>
                  Cancel
                </button>
              )}
            </form>
          </section>

          <section className="panel">
            <h2>Directory ({employees.length})</h2>
            <div className="table-wrap">
              <table className="team-table">
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Series</th>
                    <th>Name</th>
                    <th>Title</th>
                    <th>Access</th>
                    <th>Active</th>
                    <th>Telegram</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {employees.map((emp) => (
                    <tr key={emp.id}>
                      <td>
                        <code>{emp.employee_id}</code>
                      </td>
                      <td>{emp.code_series?.prefix || '—'}</td>
                      <td>{emp.name}</td>
                      <td>{emp.job_title || '—'}</td>
                      <td>{emp.role === 'manager' ? 'Admin' : 'Employee'}</td>
                      <td>{emp.active ? 'Yes' : 'No'}</td>
                      <td>{emp.telegram_linked ? 'Linked' : '—'}</td>
                      <td>
                        <div className="row gap wrap">
                          <button type="button" className="secondary small" disabled={busy} onClick={() => startEdit(emp)}>
                            Edit
                          </button>
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
                          {emp.id !== user?.id && (
                            <button
                              type="button"
                              className="secondary small"
                              disabled={busy}
                              onClick={() => {
                                if (!window.confirm(`Permanently delete ${emp.employee_id}?`)) return
                                run(async () => {
                                  await api.deleteEmployee(emp.id)
                                  if (editingId === emp.id) resetPersonForm()
                                  return { message: `${emp.employee_id} deleted` }
                                })
                              }}
                            >
                              Delete
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
                    <td>
                      <code>{row.user.employee_id}</code>
                    </td>
                    <td>{row.user.name}</td>
                    <td>
                      <span className={`pill state-${row.today.state}`}>{row.today.state.replace('_', ' ')}</span>
                    </td>
                    <td>
                      {row.today.done}/{row.today.planned}
                    </td>
                    <td>{row.today.blockers}</td>
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
            <p className="muted">Employees tap Start on the bot, then enter their employee code.</p>
            <div className="row gap wrap">
              <button disabled={busy} onClick={() => run(() => api.sendMorning())}>
                Send morning now
              </button>
              <button className="secondary" disabled={busy} onClick={() => run(() => api.sendEvening())}>
                Send evening now
              </button>
            </div>
          </section>
          <section className="panel">
            <h2>Automatic windows (Africa/Addis_Ababa)</h2>
            <p className="muted">Prompts fire once per day when the current time falls inside each window.</p>
            <div className="row gap wrap">
              <label className="inline">
                Morning from
                <input type="time" value={morning.from} onChange={(e) => setMorning({ ...morning, from: e.target.value })} />
              </label>
              <label className="inline">
                Morning to
                <input type="time" value={morning.to} onChange={(e) => setMorning({ ...morning, to: e.target.value })} />
              </label>
              <label className="inline">
                Evening from
                <input type="time" value={evening.from} onChange={(e) => setEvening({ ...evening, from: e.target.value })} />
              </label>
              <label className="inline">
                Evening to
                <input type="time" value={evening.to} onChange={(e) => setEvening({ ...evening, to: e.target.value })} />
              </label>
              <button
                disabled={busy}
                onClick={() =>
                  run(async () => {
                    await api.updateSettings({ reminder_times: { morning, evening } })
                    return { message: 'Time windows saved' }
                  })
                }
              >
                Save windows
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
