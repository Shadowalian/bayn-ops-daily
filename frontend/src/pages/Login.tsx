import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../AuthContext'

export default function Login() {
  const { user, login } = useAuth()
  const [employeeId, setEmployeeId] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  if (user) return <Navigate to={user.role === 'manager' ? '/admin' : '/'} replace />

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
        <h1>Ops Daily Check</h1>
        <p className="muted">Sign in with your employee ID. Daily plans run on Telegram.</p>
        {error && <p className="error">{error}</p>}
        <label>
          Employee ID
          <input
            value={employeeId}
            onChange={(e) => setEmployeeId(e.target.value.toUpperCase())}
            type="text"
            required
            autoComplete="username"
            placeholder="EMP001"
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
        <p className="hint">Ask your admin for your employee ID. Telegram: /start EMP001</p>
      </form>
    </div>
  )
}
