import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { guard, loadSession, login, logout, requestPasswordReset, session, type Me } from './session'

const rep: Me = { id: '1', email: 'h@example.com', name: 'Handlowiec', role: 'rep', repCode: 'PH-01', shared: true }
const admin: Me = { ...rep, role: 'admin', repCode: null }

function server(routes: Record<string, () => Response>) {
  const fetch = vi.fn(async (url: string, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${url}`
    const handler = routes[key]
    if (!handler) throw new Error('unexpected ' + key)
    return handler()
  })
  vi.stubGlobal('fetch', fetch)
  return fetch
}
const json = (status: number, body: unknown) => () => new Response(JSON.stringify(body), { status })

beforeEach(() => {
  session.me = null
  session.loaded = false
})
afterEach(() => vi.unstubAllGlobals())

describe('session', () => {
  it('loads the current account once; no account means logged out', async () => {
    const fetch = server({ 'GET /api/v1/auth/session': json(200, { me: rep }) })
    await expect(loadSession()).resolves.toEqual(rep)
    await loadSession()
    expect(fetch).toHaveBeenCalledTimes(1)

    server({ 'GET /api/v1/auth/session': json(200, { me: null }) })
    await expect(loadSession(true)).resolves.toBeNull()
    expect(session.loaded).toBe(true)
  })

  it('propagates unexpected errors when loading', async () => {
    server({ 'GET /api/v1/auth/session': json(500, { code: 'internal_error' }) })
    await expect(loadSession(true)).rejects.toMatchObject({ code: 'internal_error' })
  })

  it('logs in on the matching panel', async () => {
    server({ 'POST /api/v1/auth/login': json(200, rep) })
    await expect(login('employee', ' h@example.com ', 'Demo123!')).resolves.toEqual(rep)
    expect(session.me).toEqual(rep)
  })

  it('refuses a representative on the admin panel and logs it out again', async () => {
    const fetch = server({ 'POST /api/v1/auth/login': json(200, rep), 'POST /api/v1/auth/logout': json(200, {}) })
    await expect(login('admin', 'h@example.com', 'x')).rejects.toMatchObject({ code: 'not_admin' })
    expect(fetch.mock.calls.map((c) => c[0])).toContain('/api/v1/auth/logout')
    expect(session.me).toBeNull()

    server({ 'POST /api/v1/auth/login': json(200, admin), 'POST /api/v1/auth/logout': json(200, {}) })
    await expect(login('employee', 'a@example.com', 'x')).rejects.toMatchObject({ code: 'not_rep' })
  })

  it('clears the session on logout even if the request fails', async () => {
    session.me = rep
    server({ 'POST /api/v1/auth/logout': json(500, {}) })
    await expect(logout()).rejects.toBeDefined()
    expect(session.me).toBeNull()
  })

  it('requests a password reset with the trimmed address', async () => {
    const fetch = server({ 'POST /api/v1/auth/password-reset': json(202, { status: 'accepted' }) })
    await requestPasswordReset(' h@example.com ')
    expect((fetch.mock.calls[0]?.[1] as RequestInit).body).toBe('{"email":"h@example.com"}')
  })

  it('guards areas by role', () => {
    expect(guard(undefined, null)).toBe('allow')
    expect(guard('admin', admin)).toBe('allow')
    expect(guard('admin', rep)).toBe('login')
    expect(guard('rep', null)).toBe('login')
  })
})
