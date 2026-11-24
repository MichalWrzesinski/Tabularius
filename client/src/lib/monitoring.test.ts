import { afterEach, describe, expect, it, vi } from 'vitest'
import type { App } from 'vue'
import { DROPPED, monitoringConfig, scrubEvent, startMonitoring } from './monitoring'

afterEach(() => vi.unstubAllGlobals())

const config = { enabled: true, dsn: 'https://k@o1.ingest.sentry.io/1', release: 'abc', environment: 'staging' }
const app = {} as App

describe('monitoring', () => {
  it('stays off when the API says so or cannot be reached', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('{"enabled":false}', { status: 404 })),
    )
    const load = vi.fn()
    expect(await startMonitoring(app, load)).toBe(false)
    expect(load).not.toHaveBeenCalled()

    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('{"enabled":true}', { status: 200 })),
    )
    expect(await monitoringConfig()).toBeNull()
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('offline')
      }),
    )
    expect(await monitoringConfig()).toBeNull()
  })

  it('starts the SDK with the tunnel, the CSRF header and no user data', async () => {
    const fetch = vi.fn(async () => new Response(JSON.stringify(config), { status: 200 }))
    vi.stubGlobal('fetch', fetch)
    const init = vi.fn()
    expect(await startMonitoring(app, async () => ({ init }))).toBe(true)
    expect(fetch).toHaveBeenCalledWith('/api/monitoring/config', expect.anything())
    const options = init.mock.calls[0]?.[0]
    expect(options).toMatchObject({
      app,
      dsn: config.dsn,
      release: 'abc',
      environment: 'staging',
      tunnel: '/api/monitoring/envelope',
      transportOptions: { headers: { 'X-Tabularius-Request': '1' } },
      sendDefaultPii: false,
      tracesSampleRate: 0,
    })
    const kept = options.integrations([{ name: 'Breadcrumbs' }, { name: 'GlobalHandlers' }, { name: 'BrowserSession' }, { name: 'Vue' }])
    expect(kept.map((i: { name: string }) => i.name)).toEqual(['GlobalHandlers', 'Vue'])
    expect(DROPPED).toContain('Breadcrumbs')
    expect(options.beforeSend({ user: { ip_address: '1.2.3.4' }, request: { url: 'https://x/a?email=j@x.pl#t', cookies: 'c' } })).toEqual({
      request: { url: 'https://x/a' },
    })
  })

  it('scrubs events without a request', () => {
    expect(scrubEvent({ user: { id: 1 } })).toEqual({})
    expect(scrubEvent({ request: {} })).toEqual({ request: {} })
  })
})
