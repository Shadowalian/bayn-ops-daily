import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, type User } from './api'

const USER_CACHE_KEY = 'bayn_user'

type AuthState = {
  user: User | null
  loading: boolean
  login: (employeeId: string, password: string) => Promise<void>
  logout: () => Promise<void>
  refreshUser: (next?: User) => Promise<void>
}

const AuthContext = createContext<AuthState | null>(null)

function readCachedUser(): User | null {
  try {
    const raw = localStorage.getItem(USER_CACHE_KEY)
    return raw ? (JSON.parse(raw) as User) : null
  } catch {
    return null
  }
}

function writeCachedUser(user: User | null) {
  if (!user) {
    localStorage.removeItem(USER_CACHE_KEY)
    return
  }
  localStorage.setItem(USER_CACHE_KEY, JSON.stringify(user))
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const cached = typeof window !== 'undefined' ? readCachedUser() : null
  const hasToken = typeof window !== 'undefined' && !!localStorage.getItem('token')
  const [user, setUser] = useState<User | null>(cached && hasToken ? cached : null)
  const [loading, setLoading] = useState(hasToken && !cached)

  useEffect(() => {
    const token = localStorage.getItem('token')
    if (!token) {
      writeCachedUser(null)
      setUser(null)
      setLoading(false)
      return
    }

    let cancelled = false
    api
      .me()
      .then((r) => {
        if (cancelled) return
        setUser(r.user)
        writeCachedUser(r.user)
      })
      .catch(() => {
        if (cancelled) return
        localStorage.removeItem('token')
        writeCachedUser(null)
        setUser(null)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [])

  // Keep Render free tier awake while someone has the app open
  useEffect(() => {
    if (!user) return
    const ping = () => {
      const base = (import.meta.env.VITE_API_URL || (import.meta.env.PROD ? 'https://bayn-ops-api.onrender.com' : '')).replace(
        /\/$/,
        ''
      )
      if (!base && !import.meta.env.PROD) return
      fetch(`${base || ''}/up`, { method: 'GET', mode: 'cors', cache: 'no-store' }).catch(() => {})
    }
    ping()
    const id = window.setInterval(ping, 4 * 60 * 1000)
    return () => window.clearInterval(id)
  }, [user])

  async function login(employeeId: string, password: string) {
    const res = await api.login(employeeId, password)
    localStorage.setItem('token', res.token)
    writeCachedUser(res.user)
    setUser(res.user)
    setLoading(false)
  }

  async function logout() {
    try {
      await api.logout()
    } catch {
      /* ignore */
    }
    localStorage.removeItem('token')
    writeCachedUser(null)
    setUser(null)
  }

  async function refreshUser(next?: User) {
    if (next) {
      writeCachedUser(next)
      setUser(next)
      return
    }
    const res = await api.me()
    writeCachedUser(res.user)
    setUser(res.user)
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, refreshUser }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth outside provider')
  return ctx
}
