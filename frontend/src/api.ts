const API_BASE = import.meta.env.VITE_API_URL || ''

export type User = {
  id: number
  name: string
  email: string
  role: 'member' | 'manager'
  telegram_chat_id?: string | null
}

export type Task = {
  id: number
  title: string
  tier: 'must' | 'support'
  position: number
  result: 'planned' | 'done' | 'partial' | 'not_done' | 'dropped'
  reason_code: string | null
  reason_note: string | null
  blocked: boolean
  blocker_note: string | null
  effort: 'S' | 'M' | 'L' | null
  added_after_cutoff: boolean
  carry_count: number
  carried_from_task_id: number | null
}

export type DayPayload = {
  id: number
  date: string
  state: string
  edit_unlocked: boolean
  plan_locked: boolean
  plan_submitted_at: string | null
  closed_at: string | null
  morning_prompted_at: string | null
  evening_prompted_at: string | null
  tasks: Task[]
  settings: {
    timezone: string
    morning_time: string
    evening_time: string
  }
}

type ApiError = { code?: string; message?: string; errors?: Record<string, string[]> }

function authHeaders(): HeadersInit {
  const token = localStorage.getItem('token')
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  }
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: { ...authHeaders(), ...(options.headers || {}) },
  })

  if (!res.ok) {
    let body: ApiError = {}
    try {
      body = await res.json()
    } catch {
      /* ignore */
    }
    throw new Error(body.message || `Request failed (${res.status})`)
  }

  if (res.status === 204) return undefined as T
  return res.json() as Promise<T>
}

export const api = {
  login: (email: string, password: string) =>
    request<{ token: string; user: User }>('/api/v1/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    }),
  me: () => request<{ user: User }>('/api/v1/auth/me'),
  logout: () => request<{ ok: boolean }>('/api/v1/auth/logout', { method: 'POST' }),
  today: () => request<DayPayload>('/api/v1/days/today'),
  submitPlan: () =>
    request<DayPayload>('/api/v1/days/today/plan/submit', { method: 'POST' }),
  closeDay: () =>
    request<DayPayload>('/api/v1/days/today/close', { method: 'POST' }),
  createTask: (payload: { title: string; tier?: 'must' | 'support' }) =>
    request<Task>('/api/v1/tasks', { method: 'POST', body: JSON.stringify({ tier: 'must', ...payload }) }),
  updateTask: (id: number, payload: Partial<Task> & { reason_note?: string | null }) =>
    request<Task>(`/api/v1/tasks/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  carryOver: (id: number) =>
    request<Task>(`/api/v1/tasks/${id}/carry-over`, { method: 'POST' }),
  teamToday: () =>
    request<{
      date: string
      timezone: string
      members: Array<{
        user: User
        day: {
          id: number
          state: string
          edit_unlocked: boolean
          plan_submitted_at: string | null
          closed_at: string | null
        } | null
        must_done: number
        must_planned: number
        must_completion: number | null
        overall_done: number
        overall_planned: number
        overall_completion: number | null
        blockers: Array<{ id: number; title: string; blocker_note: string | null }>
        not_done_reasons: Array<{
          task_id: number
          title: string
          result: string
          reason_code: string | null
          reason_note: string | null
        }>
        tasks: Task[]
      }>
      needs_attention: {
        missing_plans: User[]
        not_closed: User[]
        open_blockers: Array<{ user: User; blocker: { id: number; title: string } }>
        not_linked: User[]
      }
    }>('/api/v1/team/today'),
  botStatus: () =>
    request<{
      date: string
      bot_token_set: boolean
      members: Array<{
        user: User
        telegram_linked: boolean
        state: string
        edit_unlocked: boolean
        day_id: number | null
        planned: number
        done: number
      }>
    }>('/api/v1/admin/bot/status'),
  sendMorning: () =>
    request<{ ok: boolean; sent: number; message: string }>('/api/v1/admin/bot/morning', { method: 'POST' }),
  sendEvening: () =>
    request<{ ok: boolean; sent: number; message: string }>('/api/v1/admin/bot/evening', { method: 'POST' }),
  unlockDay: (dayId: number, note: string) =>
    request<{ ok: boolean }>(`/api/v1/admin/days/${dayId}/unlock`, {
      method: 'POST',
      body: JSON.stringify({ note }),
    }),
  updateSettings: (payload: { reminder_times?: { morning?: string; evening?: string } }) =>
    request('/api/v1/settings', { method: 'PUT', body: JSON.stringify(payload) }),
  getSettings: () =>
    request<{
      reminder_times: { morning: string; evening: string }
      bot_token_set: boolean
    }>('/api/v1/settings'),
}
