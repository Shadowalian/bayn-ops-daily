import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../AuthContext'

export default function Login() {
  const { user, login } = useAuth()
  const [employeeId, setEmployeeId] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  if (user?.role === 'manager') return <Navigate to="/admin" replace />

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      await login(employeeId.trim().toUpperCase(), password)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="shell login-shell">
      <form className="panel login-panel" onSubmit={onSubmit}>
        <p className="eyebrow">BAYN TECHNOLOGIES</p>
        <h1>Ops Admin</h1>
        <p className="muted">Admin web console only. Employees plan and close their day on Telegram.</p>
        {error && <p className="error">{error}</p>}
        <label>
          Admin employee ID
          <input
            value={employeeId}
            onChange={(e) => setEmployeeId(e.target.value.toUpperCase())}
            type="text"
            required
            autoComplete="username"
            placeholder="MGR001"
            autoFocus
          />
        </label>
        <label>
          Password
          <input
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            type="password"
            required
            autoComplete="current-password"
          />
        </label>
        <button type="submit" disabled={busy}>
          {busy ? 'Signing in…' : 'Sign in'}
        </button>
        <p className="hint">Employees: open the Telegram bot → Start → enter your employee code.</p>
      </form>
    </div>
  )
}
