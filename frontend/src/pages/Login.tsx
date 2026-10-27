import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../AuthContext'

export default function Login() {
  const { user, login } = useAuth()
  const [email, setEmail] = useState('hana@bayn.local')
  const [password, setPassword] = useState('password')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  if (user) return <Navigate to="/" replace />

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      await login(email, password)
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
        <p className="muted">Telegram morning plan + evening review. Web for team & admin.</p>
        {error && <p className="error">{error}</p>}
        <label>
          Email
          <input value={email} onChange={(e) => setEmail(e.target.value)} type="email" required autoComplete="username" />
        </label>
        <label>
          Password
          <input value={password} onChange={(e) => setPassword(e.target.value)} type="password" required autoComplete="current-password" />
        </label>
        <button type="submit" disabled={busy}>
          {busy ? 'Signing in…' : 'Sign in'}
        </button>
        <p className="hint">Demo: hana@bayn.local / password · manager@bayn.local / password</p>
      </form>
    </div>
  )
}
