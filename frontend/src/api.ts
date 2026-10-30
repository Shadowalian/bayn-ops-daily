// Always hit Render in production. Relative /api on Vercel returns HTML (SPA) and breaks JSON.parse.
const API_BASE = (
  import.meta.env.VITE_API_URL ||
  (import.meta.env.DEV ? '' : 'https://bayn-ops-api.onrender.com')
).replace(/\/$/, '') || (import.meta.env.DEV ? '' : 'https://bayn-ops-api.onrender.com')

export type User = {
  id: number
  employee_id: string
  code_series_id?: number | null
  code_series?: { id: number; prefix: string; name: string } | null
  name: string
  email?: string
  role: 'member' | 'manager'
  job_title?: string | null
  telegram_chat_id?: string | null
  telegram_linked?: boolean
  active?: boolean
}

export type CodeSeries = {
  id: number
  prefix: string
  name: string
  access_role: 'member' | 'manager'
  next_number: number
  pad_length: number
  active: boolean
  next_code: string
  users_count: number
}

export type TimeWindow = { from: string; to: string }

export type WeeklyGoalItem = {
  id: number
  title: string
  status: 'pending' | 'approved' | 'rejected' | 'remove_requested' | 'removed'
  source: 'self' | 'admin'
  position?: number
  review_note?: string | null
  remove_request_note?: string | null
  week_marks?: Record<string, string> | null
}

export type WeeklyPlan = {
  id: number
  user_id: number
  employee_id?: string
  name?: string
  week_start: string
  status: 'pending' | 'approved' | 'rejected'
  submitted_at?: string | null
  reviewed_at?: string | null
  rejection_reason?: string | null
  goals: WeeklyGoalItem[]
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

export type Dashboard = {
  timezone: string
  today: string
  range: { from: string; to: string }
  kpis: {
    active_members: number
    telegram_linked: number
    telegram_link_rate: number
    plans_submitted_today: number
    days_closed_today: number
    open_blockers_today: number
    team_completion_today: number | null
    team_completion_period: number | null
    tasks_done_today: number
    tasks_planned_today: number
    tasks_done_period: number
    tasks_planned_period: number
  }
  employees: Array<{
    user: User
    today: {
      state: string
      done: number
      planned: number
      completion: number | null
      blockers: number
      day_id: number | null
      edit_unlocked: boolean
    }
    period: {
      from: string
      to: string
      done: number
      planned: number
      not_done: number
      completion: number | null
      plans_submitted: number
      days_closed: number
      working_days: number
      plan_submission_rate: number | null
      close_rate: number | null
      carry_over_hot: number
    }
  }>
  reason_breakdown: Array<{ reason_code: string; count: number }>
  daily_trend: Array<{ date: string; planned: number; done: number; completion: number | null }>
  needs_attention: {
    missing_plans: User[]
    not_closed: User[]
    not_linked: User[]
    roadblocks: Array<{
      employee_id: string | null
      name: string
      task_id: number
      title: string
      blocker_note: string | null
    }>
  }
  directory_count: {
    total: number
    managers: number
    members: number
    inactive: number
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

async function parseJson<T>(res: Response): Promise<T> {
  const text = await res.text()
  if (!text) return undefined as T
  try {
    return JSON.parse(text) as T
  } catch {
    throw new Error(
      `API returned non-JSON (${res.status}). Check VITE_API_URL points at the Render API.`
    )
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
      body = await parseJson<ApiError>(res)
    } catch {
      /* ignore */
    }
    throw new Error(body.message || `Request failed (${res.status})`)
  }

  if (res.status === 204) return undefined as T
  return parseJson<T>(res)
}

export const REASON_LABELS: Record<string, string> = {
  took_longer: 'Took longer than planned',
  waiting_external: 'Waiting external',
  waiting_internal: 'Waiting internal',
  priority_changed: 'Priority changed',
  interrupted: 'Interrupted',
  blocked: 'Blocked',
  other: 'Other',
  unspecified: 'Unspecified',
}

export const api = {
  login: (employee_id: string, password: string) =>
    request<{ token: string; user: User }>('/api/v1/auth/login', {
      method: 'POST',
      body: JSON.stringify({ employee_id, password }),
    }),
  me: () => request<{ user: User }>('/api/v1/auth/me'),
  updateProfile: (payload: { name?: string; job_title?: string | null }) =>
    request<{ user: User; message: string }>('/api/v1/auth/me', {
      method: 'PATCH',
      body: JSON.stringify(payload),
    }),
  changePassword: (payload: {
    current_password: string
    password: string
    password_confirmation: string
  }) =>
    request<{ ok: boolean; message: string; user: User }>('/api/v1/auth/change-password', {
      method: 'POST',
      body: JSON.stringify(payload),
    }),
  logout: () => request<{ ok: boolean }>('/api/v1/auth/logout', { method: 'POST' }),
  history: (userId: number) =>
    request<{ data: Array<{
      id: number
      date: string
      work_status: string
      check_in_at: string | null
      check_out_at: string | null
      closed_at: string | null
      tasks: Array<{ id: number; title: string; tier: string; result: string }>
    }> }>(`/api/v1/users/${userId}/days`),
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
  teamToday: (date?: string) => {
    const q = date ? `?date=${encodeURIComponent(date)}` : ''
    return request<{
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
        tasks?: Task[]
      }>
      needs_attention: {
        missing_plans: User[]
        not_closed: User[]
        open_blockers: Array<{ user: User; blocker: { id: number; title: string } }>
        not_linked: User[]
      }
    }>(`/api/v1/team/today${q}`)
  },
  dashboard: (from?: string, to?: string) => {
    const q = new URLSearchParams()
    if (from) q.set('from', from)
    if (to) q.set('to', to)
    const suffix = q.toString() ? `?${q}` : ''
    return request<Dashboard>(`/api/v1/admin/dashboard${suffix}`)
  },
  listEmployees: () => request<{ employees: User[] }>('/api/v1/admin/employees'),
  nextEmployeeCode: (opts: { role?: 'member' | 'manager'; prefix?: string; series_id?: number }) => {
    const q = new URLSearchParams()
    if (opts.series_id) q.set('series_id', String(opts.series_id))
    if (opts.role) q.set('role', opts.role)
    if (opts.prefix) q.set('prefix', opts.prefix)
    return request<{ employee_id: string; prefix: string; series_id?: number; access_role?: string }>(
      `/api/v1/admin/employees/next-code?${q}`
    )
  },
  createEmployee: (payload: {
    code_series_id: number
    employee_id?: string
    name: string
    job_title?: string
    password?: string
    active?: boolean
  }) =>
    request<User>('/api/v1/admin/employees', { method: 'POST', body: JSON.stringify(payload) }),
  updateEmployee: (
    id: number,
    payload: Partial<{
      employee_id: string
      name: string
      role: 'member' | 'manager'
      code_series_id: number | null
      job_title: string | null
      password: string
      active: boolean
      telegram_chat_id: string | null
      clear_telegram: boolean
    }>
  ) =>
    request<User>(`/api/v1/admin/employees/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  deactivateEmployee: (id: number) =>
    request<User>(`/api/v1/admin/employees/${id}`, { method: 'DELETE' }),
  deleteEmployee: (id: number) =>
    request<{ ok: boolean; deleted: string }>(`/api/v1/admin/employees/${id}?hard=1`, { method: 'DELETE' }),
  listCodeSeries: () => request<{ series: CodeSeries[] }>('/api/v1/admin/code-series'),
  createCodeSeries: (payload: {
    prefix: string
    name?: string
    access_role?: 'member' | 'manager'
    next_number?: number
    pad_length?: number
    active?: boolean
  }) => request<CodeSeries>('/api/v1/admin/code-series', { method: 'POST', body: JSON.stringify(payload) }),
  updateCodeSeries: (
    id: number,
    payload: Partial<{
      prefix: string
      name: string
      access_role: 'member' | 'manager'
      next_number: number
      pad_length: number
      active: boolean
    }>
  ) => request<CodeSeries>(`/api/v1/admin/code-series/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  deleteCodeSeries: (id: number) =>
    request<{ ok: boolean; deleted: string }>(`/api/v1/admin/code-series/${id}`, { method: 'DELETE' }),
  assignTask: (payload: { user_id: number; title: string; date?: string; scope?: 'week' | 'today' }) =>
    request<{ message: string; task?: { id: number; title: string; employee_id?: string; name?: string } }>(
      '/api/v1/admin/assign-task',
      { method: 'POST', body: JSON.stringify(payload) }
    ),
  listWeeklyPlans: (week_start?: string) => {
    const q = week_start ? `?week_start=${encodeURIComponent(week_start)}` : ''
    return request<{ week_start: string; plans: WeeklyPlan[] }>(`/api/v1/admin/weekly-plans${q}`)
  },
  approveWeeklyPlan: (id: number) =>
    request<WeeklyPlan>(`/api/v1/admin/weekly-plans/${id}/approve`, { method: 'POST', body: '{}' }),
  rejectWeeklyPlan: (id: number, reason: string) =>
    request<WeeklyPlan>(`/api/v1/admin/weekly-plans/${id}/reject`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    }),
  approveWeeklyGoal: (id: number) =>
    request<{ goal: WeeklyGoalItem }>(`/api/v1/admin/weekly-goals/${id}/approve`, { method: 'POST', body: '{}' }),
  rejectWeeklyGoal: (id: number, reason: string) =>
    request<{ goal: WeeklyGoalItem }>(`/api/v1/admin/weekly-goals/${id}/reject`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    }),
  approveWeeklyGoalRemoval: (id: number) =>
    request<{ goal: WeeklyGoalItem }>(`/api/v1/admin/weekly-goals/${id}/approve-removal`, {
      method: 'POST',
      body: '{}',
    }),
  denyWeeklyGoalRemoval: (id: number, reason: string) =>
    request<{ goal: WeeklyGoalItem }>(`/api/v1/admin/weekly-goals/${id}/deny-removal`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    }),
  removeWeeklyGoal: (id: number, note?: string) =>
    request<{ goal: WeeklyGoalItem }>(`/api/v1/admin/weekly-goals/${id}/remove`, {
      method: 'POST',
      body: JSON.stringify({ note: note || null }),
    }),
  updateWeeklyGoal: (id: number, title: string) =>
    request<{ goal: WeeklyGoalItem }>(`/api/v1/admin/weekly-goals/${id}`, {
      method: 'PATCH',
      body: JSON.stringify({ title }),
    }),
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
        completion: number | null
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
  updateSettings: (payload: {
    reminder_times?: { morning?: TimeWindow; evening?: TimeWindow }
    weekly_plans_enabled?: boolean
  }) => request('/api/v1/settings', { method: 'PUT', body: JSON.stringify(payload) }),
  getSettings: () =>
    request<{
      reminder_times: { morning: TimeWindow; evening: TimeWindow }
      weekly_plans_enabled?: boolean
      bot_token_set: boolean
    }>('/api/v1/settings'),
  exportCsv: (table: 'tasks' | 'attendance' | 'reasons', from: string, to: string) => {
    const token = localStorage.getItem('token')
    const url = `${API_BASE}/api/v1/exports/${table}.csv?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`
    return fetch(url, { headers: { Authorization: `Bearer ${token}` } }).then(async (res) => {
      if (!res.ok) throw new Error('Export failed')
      const blob = await res.blob()
      const a = document.createElement('a')
      a.href = URL.createObjectURL(blob)
      a.download = `${table}-${from}-to-${to}.csv`
      a.click()
    })
  },
}
