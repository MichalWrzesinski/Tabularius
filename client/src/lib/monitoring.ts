// Dashboard error reporting (TASK-015). Off unless the API says it is on. Events go to the API's tunnel, never straight
// to Sentry (CSP `connect-src 'self'`); the API scrubs them again before forwarding. No user data, no performance
// tracing, no session replay. The SDK is loaded only when monitoring is on.
import type { App } from 'vue'
import { CSRF_HEADER } from './http'

export interface MonitoringConfig {
  enabled: boolean
  dsn: string
  release: string
  environment: string
}

export const DROPPED = ['Breadcrumbs', 'BrowserSession']

type Sdk = Pick<typeof import('@sentry/vue'), 'init'>
type SentryEvent = { user?: unknown; request?: { url?: string; cookies?: unknown; query_string?: unknown } }

export async function monitoringConfig(): Promise<MonitoringConfig | null> {
  try {
    const response = await fetch('/api/monitoring/config', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    if (!response.ok) return null
    const config = (await response.json()) as Partial<MonitoringConfig>
    return config.enabled === true && typeof config.dsn === 'string'
      ? { enabled: true, dsn: config.dsn, release: String(config.release ?? ''), environment: String(config.environment ?? '') }
      : null
  } catch {
    return null
  }
}

/** Removes what the browser could add about the visitor: the user, cookies and the query string. */
export function scrubEvent<T extends SentryEvent>(event: T): T {
  delete event.user
  if (event.request) {
    delete event.request.cookies
    delete event.request.query_string
    if (event.request.url) event.request.url = event.request.url.split(/[?#]/)[0]
  }
  return event
}

export async function startMonitoring(app: App, load: () => Promise<Sdk> = () => import('@sentry/vue')): Promise<boolean> {
  const config = await monitoringConfig()
  if (!config) return false
  const sdk = await load()
  sdk.init({
    app,
    dsn: config.dsn,
    release: config.release,
    environment: config.environment,
    tunnel: '/api/monitoring/envelope',
    transportOptions: { headers: { [CSRF_HEADER]: '1' } },
    sendDefaultPii: false,
    // Breadcrumbs could carry console output and request URLs; sessions are not needed. Error capture stays.
    integrations: (defaults) => defaults.filter((i) => !DROPPED.includes(i.name)),
    tracesSampleRate: 0,
    beforeSend: (event) => scrubEvent(event),
  })
  return true
}
