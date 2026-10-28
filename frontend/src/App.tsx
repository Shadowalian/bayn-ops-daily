import { NavLink, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './AuthContext'
import Admin from './pages/Admin'
import History from './pages/History'
import Login from './pages/Login'
import MyDay from './pages/MyDay'
import TeamToday from './pages/TeamToday'

function Shell() {
  const { user, loading, logout } = useAuth()

  if (loading) return <div className="shell">Loading…</div>
  if (!user) return <Login />

  return (
    <div className="app">
      <nav className="topnav">
        <div className="brand">
          <span className="brand-mark">BAYN</span>
          <span className="brand-sub">Ops Check</span>
        </div>
        <div className="nav-links">
          <NavLink to="/" end>
            My Day
          </NavLink>
          <NavLink to="/team">Team</NavLink>
          <NavLink to="/history">History</NavLink>
          {user.role === 'manager' && <NavLink to="/admin">Admin</NavLink>}
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
          <Route path="/" element={<MyDay />} />
          <Route path="/team" element={<TeamToday />} />
          <Route path="/history" element={<History />} />
          <Route path="/admin" element={<Admin />} />
          <Route path="*" element={<Navigate to="/" replace />} />
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
