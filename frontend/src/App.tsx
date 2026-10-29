import { NavLink, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './AuthContext'
import Admin from './pages/Admin'
import Login from './pages/Login'
import TeamToday from './pages/TeamToday'

function Shell() {
  const { user, loading, logout } = useAuth()

  if (loading) return <div className="shell">Loading…</div>
  if (!user) return <Login />

  if (user.role !== 'manager') {
    return (
      <div className="shell login-shell">
        <div className="panel login-panel">
          <p className="eyebrow">BAYN OPS</p>
          <h1>Admin web only</h1>
          <p className="muted">
            Employees use the Telegram bot. Open the bot, tap <strong>Start</strong>, and enter your employee code.
          </p>
          <button type="button" onClick={() => logout()}>
            Log out
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="app">
      <nav className="topnav">
        <div className="brand">
          <span className="brand-mark">BAYN</span>
          <span className="brand-sub">Ops Admin</span>
        </div>
        <div className="nav-links">
          <NavLink to="/admin">Admin</NavLink>
          <NavLink to="/team">Team</NavLink>
        </div>
        <div className="nav-user">
          <span>
            {user.name} · <code>{user.employee_id}</code>
          </span>
          <button type="button" className="secondary small" onClick={() => logout()}>
            Log out
          </button>
        </div>
      </nav>
      <main>
        <Routes>
          <Route path="/admin" element={<Admin />} />
          <Route path="/team" element={<TeamToday />} />
          <Route path="*" element={<Navigate to="/admin" replace />} />
        </Routes>
      </main>
    </div>
  )
}

export default function App() {
  return (
    <AuthProvider>
      <Shell />
    </AuthProvider>
  )
}
