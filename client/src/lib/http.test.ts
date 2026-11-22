import { afterEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, CSRF_HEADER, errorMessage } from './http'

function respond(status: number, body: unknown) {
  return vi.fn(
    async () => new Response(body === undefined ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

afterEach(() => vi.unstubAllGlobals())

describe('api', () => {
  it('sends the CSRF header and JSON body on state-changing requests only', async () => {
    const fetch = respond(200, { ok: true })
    vi.stubGlobal('fetch', fetch)

    await api('POST', '/x', { a: 1 })
    await api('GET', '/y')

    const [url, post] = fetch.mock.calls[0] as unknown as [string, RequestInit]
    const [, get] = fetch.mock.calls[1] as unknown as [string, RequestInit]
    expect(url).toBe('/api/v1/x')
    expect((post.headers as Record<string, string>)[CSRF_HEADER]).toBe('1')
    expect(post.body).toBe('{"a":1}')
    expect(post.credentials).toBe('same-origin')
    expect((get.headers as Record<string, string>)[CSRF_HEADER]).toBeUndefined()
  })

  it('returns undefined for 204', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response(null, { status: 204 })),
    )
    await expect(api('DELETE', '/x')).resolves.toBeUndefined()
  })

  it('turns Problem Details into ApiError with the code', async () => {
    vi.stubGlobal('fetch', respond(409, { code: 'email_taken', title: 'Request refused' }))
    await expect(api('POST', '/x', {})).rejects.toMatchObject({ status: 409, code: 'email_taken' })
  })

  it('handles non-JSON errors and network failures', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('<html>', { status: 502 })),
    )
    await expect(api('GET', '/x')).rejects.toMatchObject({ status: 502, code: 'http_error' })

    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('offline')
      }),
    )
    await expect(api('GET', '/x')).rejects.toMatchObject({ status: 0, code: 'network' })
  })
})

describe('errorMessage', () => {
  it('maps known codes to Polish messages and falls back safely', () => {
    expect(errorMessage(new ApiError(401, 'invalid_credentials', 'x'))).toBe('Nieprawidłowy e-mail lub hasło.')
    expect(errorMessage(new ApiError(429, 'too_many_attempts', 'x'))).toContain('Zbyt wiele prób')
    expect(errorMessage(new ApiError(500, 'internal_error', 'secret detail'))).toBe('Nie udało się wykonać operacji. Spróbuj ponownie.')
    expect(errorMessage(new Error('x'))).toBe('Wystąpił nieoczekiwany błąd.')
  })
})
