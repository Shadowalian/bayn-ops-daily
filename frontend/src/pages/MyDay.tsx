import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, type DayPayload, type Task } from '../api'

export default function MyDay() {
  const [day, setDay] = useState<DayPayload | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [title, setTitle] = useState('')

  const load = useCallback(async () => {
    try {
      const d = await api.today()
      setDay(d)
      setError('')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load day')
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  async function run(action: () => Promise<unknown>) {
    setBusy(true)
    setError('')
    try {
      await action()
      await load()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action failed')
    } finally {
      setBusy(false)
    }
  }

  async function addTask(e: FormEvent) {
    e.preventDefault()
    if (!title.trim() || !day || day.plan_locked || day.closed_at) return
    await run(() => api.createTask({ title: title.trim() }))
    setTitle('')
  }

  if (!day) {
    return <div className="shell">{error || 'Loading your day…'}</div>
  }

  const tasks = day.tasks
  const canEditPlan = !day.plan_locked && !day.closed_at
  const canReview = !!day.plan_submitted_at && !day.closed_at
  const allReviewed = tasks.length > 0 && tasks.every((t) => t.result !== 'planned')

  return (
    <div className="page">
      <header className="page-head">
        <div>
          <p className="eyebrow">My Day · {day.date}</p>
          <h1>Today</h1>
          <p className="muted">Prefer Telegram for morning plan & evening close. This page is a backup view.</p>
        </div>
        <span className={`pill state-${day.state}`}>{day.state.replace('_', ' ')}</span>
      </header>

      {error && <p className="error">{error}</p>}

      {day.plan_locked && (
        <section className="panel attention">
          <p>
            Plan is <strong>locked</strong>. To change it, ask the admin to unlock editing.
          </p>
        </section>
      )}
      {day.edit_unlocked && (
        <section className="panel">
          <p>Admin unlocked editing — submit a new plan (Telegram or here), then it locks again.</p>
        </section>
      )}

      <section className="panel">
        <div className="row between">
          <h2>Daily tasks</h2>
          <span className="muted">{tasks.length} task(s)</span>
        </div>

        {canEditPlan && (
          <form className="add-task" onSubmit={addTask}>
            <input
              placeholder="Add a task"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              maxLength={200}
              required
            />
            <button type="submit" disabled={busy}>
              Add
            </button>
          </form>
        )}

        <ul className="task-list">
          {tasks.map((task) => (
            <TaskRow
              key={task.id}
              task={task}
              canReview={canReview}
              busy={busy}
              onPatch={(payload) => run(() => api.updateTask(task.id, payload))}
            />
          ))}
        </ul>

        {canEditPlan && tasks.length > 0 && (
          <button disabled={busy} onClick={() => run(() => api.submitPlan())}>
            Lock plan (same as sending on Telegram)
          </button>
        )}

        {canReview && (
          <div className="row gap" style={{ marginTop: '1rem' }}>
            <button disabled={busy || !allReviewed} onClick={() => run(() => api.closeDay())}>
              Send & close day
            </button>
            {!allReviewed && <span className="muted">Mark every task ✅ or ❌ (+ roadblock) first.</span>}
          </div>
        )}

        {day.closed_at && <p className="hint">Day closed. Good night.</p>}
      </section>
    </div>
  )
}

function TaskRow({
  task,
  canReview,
  busy,
  onPatch,
}: {
  task: Task
  canReview: boolean
  busy: boolean
  onPatch: (payload: Partial<Task> & { reason_note?: string | null }) => Promise<void>
}) {
  return (
    <li className="task-card">
      <div className="row between">
        <strong>
          {task.position}) {task.title}
        </strong>
        <span className="muted">{task.result}</span>
      </div>

      {canReview && (
        <>
          <div className="row gap wrap">
            <button
              type="button"
              className={task.result === 'done' ? '' : 'secondary'}
              disabled={busy}
              onClick={() => onPatch({ result: 'done' })}
            >
              ✅ Done
            </button>
            <button
              type="button"
              className={task.result === 'not_done' ? '' : 'secondary'}
              disabled={busy}
              onClick={() =>
                onPatch({
                  result: 'not_done',
                  reason_code: 'blocked',
                  reason_note: task.reason_note || 'Pending explanation',
                })
              }
            >
              ❌ Not done
            </button>
          </div>
          {task.result === 'not_done' && (
            <input
              placeholder="Roadblock / why it was not finished"
              defaultValue={task.reason_note || ''}
              onBlur={(e) => {
                const v = e.target.value.trim()
                if (v && v !== (task.reason_note || '')) {
                  onPatch({ result: 'not_done', reason_code: 'blocked', reason_note: v })
                }
              }}
            />
          )}
        </>
      )}

      {!canReview && task.reason_note && <p className="muted">Roadblock: {task.reason_note}</p>}
    </li>
  )
}
