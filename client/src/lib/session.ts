// Server session state (ADR 0003). The cookie is HttpOnly; the client only knows who is logged in via /auth/me.
import { reactive } from 'vue'
import { api, ApiError } from './http'

export interface Me {
  id: string
  email: string
  name: string
  role: 'admin' | 'rep'
  repCode: string | null
  shared: boolean
}

export const session = reactive({
  me: null as Me | null,
  loaded: false,
  /** Environment from the server: demo (public, shared accounts) or production (organization). */
  mode: 'demo' as 'demo' | 'production',
  /** Public mailbox address requests are sent to. */
  mailbox: '',
})

let loading: Promise<Me | null> | null = null

export function loadSession(force = false): Promise<Me | null> {
  if (session.loaded && !force) return Promise.resolve(session.me)
  loading ??= api<{ me: Me | null; mode?: 'demo' | 'production'; mailbox?: string }>('GET', '/auth/session')
    .then(({ me, mode, mailbox }) => {
      session.me = me
      session.loaded = true
      session.mode = mode === 'production' ? 'production' : 'demo'
      session.mailbox = mailbox ?? ''
      return me
    })
    .finally(() => {
      loading = null
    })
  return loading
}

/** Logs in; refuses an account whose role does not match the panel (the server enforces roles anyway). */
export async function login(panel: 'admin' | 'employee', email: string, password: string): Promise<Me> {
  const me = await api<Me>('POST', '/auth/login', { email: email.trim(), password })
  const expected = panel === 'admin' ? 'admin' : 'rep'
  if (me.role !== expected) {
    await logout()
    throw new ApiError(403, panel === 'admin' ? 'not_admin' : 'not_rep', 'Wrong panel')
  }
  session.me = me
  session.loaded = true
  return me
}

export async function logout(): Promise<void> {
  try {
    await api('POST', '/auth/logout')
  } finally {
    session.me = null
    session.loaded = true
  }
}

export function requestPasswordReset(email: string): Promise<unknown> {
  return api('POST', '/auth/password-reset', { email: email.trim() })
}

/** Route guard decision for a role-protected area. */
export function guard(required: 'admin' | 'rep' | undefined, me: Me | null): 'allow' | 'login' {
  if (!required) return 'allow'
  return me?.role === required ? 'allow' : 'login'
}
