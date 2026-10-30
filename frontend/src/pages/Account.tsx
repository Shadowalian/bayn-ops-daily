import { useEffect, useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../AuthContext'

export default function Account() {
  const { user, refreshUser } = useAuth()
  const [name, setName] = useState(user?.name || '')
  const [jobTitle, setJobTitle] = useState(user?.job_title || '')
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [profileMsg, setProfileMsg] = useState('')
  const [passwordMsg, setPasswordMsg] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!user) return
    setName(user.name)
    setJobTitle(user.job_title || '')
  }, [user])

  if (!user) return <Navigate to="/" replace />
  if (user.role !== 'manager') return <Navigate to="/" replace />

  async function saveProfile(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    setProfileMsg('')
    try {
      const res = await api.updateProfile({
        name: name.trim(),
        job_title: jobTitle.trim() || null,
      })
      await refreshUser(res.user)
      setProfileMsg(res.message || 'Profile updated')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Profile update failed')
    } finally {
      setBusy(false)
    }
  }

  async function savePassword(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    setPasswordMsg('')
    try {
      const res = await api.changePassword({
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      })
      if (res.user) await refreshUser(res.user)
      setCurrentPassword('')
      setPassword('')
      setPasswordConfirmation('')
      setPasswordMsg(res.message || 'Password updated')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Password change failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page">
      <header className="page-head">
        <div>
          <p className="eyebrow">Your account</p>
          <h1>Account settings</h1>
          <p className="muted">
            Signed in as <code>{user.employee_id}</code>
            {user.job_title ? ` · ${user.job_title}` : ''}
          </p>
        </div>
      </header>

      {error && <p className="error">{error}</p>}

      <section className="panel">
        <h2>Profile</h2>
        <p className="muted">Name and title show in the admin bar and team directory.</p>
        {profileMsg && <p className="ok">{profileMsg}</p>}
        <form className="account-form" onSubmit={saveProfile}>
          <label>
            Display name
            <input required value={name} onChange={(e) => setName(e.target.value)} maxLength={120} />
          </label>
          <label>
            Job title
            <input
              value={jobTitle}
              onChange={(e) => setJobTitle(e.target.value)}
              placeholder="Optional"
              maxLength={120}
            />
          </label>
          <button type="submit" disabled={busy}>
            Save profile
          </button>
        </form>
      </section>

      <section className="panel">
        <h2>Change password</h2>
        <p className="muted">Requires your current password. Other sessions are signed out.</p>
        {passwordMsg && <p className="ok">{passwordMsg}</p>}
        <form className="account-form" onSubmit={savePassword}>
          <label>
            Current password
            <input
              type="password"
              required
              autoComplete="current-password"
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
            />
          </label>
          <label>
            New password
            <input
              type="password"
              required
              minLength={6}
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </label>
          <label>
            Confirm new password
            <input
              type="password"
              required
              minLength={6}
              autoComplete="new-password"
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
            />
          </label>
          <button type="submit" disabled={busy}>
            Update password
          </button>
        </form>
      </section>
    </div>
  )
}
