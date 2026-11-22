// PROTOTYPE: in-memory mocked tickets and reports with artificial latency and switchable failures, used until
// TASK-005 connects them to the API. Login, accounts and audit are real (lib/session.ts, lib/http.ts).
// None of this is a security boundary.
import { reactive } from 'vue'
import { session } from '../lib/session'
import { buildFixtures, discountFile, DEMO_REP_ID, NOW } from './data'
import type { ReportFile, Ticket, TicketEvent } from './types'

export type Mode = 'demo' | 'production'

/** Mock data belongs to the shared demo representative (PH-01); other real accounts see none of it. */
function viewer(): { admin: boolean; owner: string | null } {
  const me = session.me
  return { admin: me?.role === 'admin', owner: me?.repCode === 'PH-01' ? DEMO_REP_ID : null }
}

const fixtures = buildFixtures()

export const proto = reactive({
  mode: 'demo' as Mode,
  /** Public-demo view rules (ID-DEMO-3): aliases instead of names, no message content. Off = trusted/organization view as in the mockups. */
  anonymize: false,
  latencyMs: 450,
  failLists: false,
  emptyEmployee: false,
})

const db = reactive({
  tickets: fixtures.tickets,
  files: fixtures.files,
  audit: fixtures.audit,
})

let clock = new Date(NOW).getTime()
function now() {
  clock += 4000
  return new Date(clock).toISOString()
}

function wait<T>(value: () => T, ms = proto.latencyMs): Promise<T> {
  return new Promise((resolve, reject) =>
    setTimeout(() => {
      try {
        resolve(value())
      } catch (e) {
        reject(e)
      }
    }, ms),
  )
}

export class ApiError extends Error {
  constructor(
    public code: 'network' | 'forbidden' | 'not_found' | 'conflict',
    message: string,
  ) {
    super(message)
  }
}

function failIfConfigured() {
  if (proto.failLists) throw new ApiError('network', 'Nie udało się pobrać danych. Sprawdź połączenie i spróbuj ponownie.')
}

function ticket(id: string): Ticket {
  const t = db.tickets.find((x) => x.id === id)
  if (!t) throw new ApiError('not_found', 'Nie znaleziono zgłoszenia.')
  return t
}

function event(t: Ticket, actor: TicketEvent['actor'], actorName: string, text: string) {
  t.events.push({ at: now(), actor, actorName, text })
}

function audit(actor: string, action: string, target: string) {
  db.audit.unshift({ at: now(), actor, action, target })
}

// ---- admin ------------------------------------------------------------

export function listTickets() {
  return wait(() => {
    failIfConfigured()
    return [...db.tickets].sort((a, b) => b.receivedAt.localeCompare(a.receivedAt))
  })
}

export function getTicket(id: string) {
  return wait(() => {
    const t = ticket(id)
    const v = viewer()
    if (!v.admin && t.ownerId !== v.owner) throw new ApiError('forbidden', 'Nie masz dostępu do tego zgłoszenia.')
    return t
  })
}

export function getFile(id: string) {
  return wait(() => {
    const f = db.files.find((x) => x.id === id)
    if (!f) throw new ApiError('not_found', 'Nie znaleziono pliku.')
    const v = viewer()
    // ACC-FILE-1 imitation: owner or administrator only.
    if (!v.admin && f.ownerId !== v.owner) {
      throw new ApiError('forbidden', 'Nie masz dostępu do tego raportu.')
    }
    return f
  })
}

export function listFiles() {
  return wait(() => {
    failIfConfigured()
    return [...db.files].sort((a, b) => b.createdAt.localeCompare(a.createdAt))
  })
}

// ---- ticket flow imitation ---------------------------------------------

function complete(t: Ticket, file: ReportFile) {
  t.status = 'running'
  event(t, 'system', 'System', 'Ponowna kontrola uprawnień przed wykonaniem: OK; raport w realizacji')
  setTimeout(() => {
    db.files.unshift(file)
    t.fileId = file.id
    t.status = 'completed'
    event(t, 'system', 'System', `Raport wygenerowany: ${file.name}`)
    // Notifications are delivery events, not correspondence (mockup: „Korespondencja 1”).
    event(t, 'system', 'Poczta', 'Powiadomienie z linkiem przyjęte przez serwer poczty (szablon report_ready)')
  }, 1600)
}

function newId() {
  return `f-${Math.random().toString(16).slice(2, 8)}`
}

function salesFileFor(t: Ticket, period: string, from: string, to: string): ReportFile {
  const base = db.files.find((f) => f.id === 'f-7c1e9a')!
  const created = now()
  return {
    ...base,
    id: newId(),
    ticketId: t.id,
    name: `sprzedaz_${from}_${to}.csv`.replace(/_(\d{4}-\d{2})-01_\1-\d{2}\.csv$/, '_$1.csv'),
    periodLabel: period,
    createdAt: created,
    expiresAt: new Date(new Date(created).getTime() + 7 * 86400000).toISOString(),
    rows: base.rows.map((r) =>
      r.cells[2] === 'Okres' ? { ...r, cells: r.cells.map((c, i) => (i === 4 ? `${from} – ${to} (Europe/Warsaw)` : c)) } : r,
    ),
  }
}

/** Simulates a correlated reply in the thread of T-1040 (TKT-CLAR / TKT-VER walkthrough). */
export function simulateReply(id: string, text: string) {
  return wait(() => {
    const t = ticket(id)
    if (t.status !== 'needs_clarification') throw new ApiError('conflict', 'Zgłoszenie nie czeka już na odpowiedź.')
    t.messages.push({ id: `m-${Date.now()}`, direction: 'in', at: now(), kind: 'reply', body: text })
    event(t, 'system', 'System', 'Odpowiedź skorelowana z wątkiem (In-Reply-To + uczestnik)')
    t.status = 'interpreting'
    setTimeout(() => {
      const prev = t.plans[t.plans.length - 1]!
      const version = prev.version + 1
      t.plans.push({
        version,
        author: 'model',
        at: now(),
        operation: prev.operation,
        params: prev.params.map((p) => (p.label === 'Okres' ? { label: 'Okres', value: '01–31.10.2026' } : p)),
        issues: [],
      })
      event(t, 'model', 'Interpretacja', `Plan v${version}: uzupełniono okres 01–31.10.2026; pozostałe parametry zachowane`)
      t.status = 'queued'
      event(t, 'system', 'System', `Plan v${version} w kolejce (jedno wykonanie na wersję)`)
      setTimeout(() => complete(t, { ...discountFile(t.id, now()), id: newId() }), 900)
    }, 1300)
    return t
  })
}

/** Admin retries execution after a data-source error (same plan version, idempotent). */
export function adminRetryExecution(id: string) {
  return wait(() => {
    const t = ticket(id)
    if (t.status !== 'needs_intervention') throw new ApiError('conflict', 'Zgłoszenie nie wymaga już interwencji.')
    t.reason = undefined
    event(t, 'admin', 'Administrator', 'Ponowienie wykonania planu v1 (bez zmiany parametrów)')
    audit('Administrator', 'Ponowienie wykonania', t.id)
    t.status = 'queued'
    setTimeout(() => complete(t, salesFileFor(t, '08–14.11.2026', '2026-11-08', '2026-11-14')), 900)
    return t
  })
}

/** Admin sets parameters manually after an AI provider error (CP-005). */
export function adminResolveSales(id: string, monthLabel: string, from: string, to: string) {
  return wait(() => {
    const t = ticket(id)
    if (t.status !== 'needs_intervention') throw new ApiError('conflict', 'Zgłoszenie nie wymaga już interwencji.')
    const version = (t.plans[t.plans.length - 1]?.version ?? 0) + 1
    t.plans.push({
      version,
      author: 'admin',
      at: now(),
      operation: 'sales_report',
      params: [
        { label: 'Okres', value: monthLabel },
        { label: 'Zakres', value: 'Przypisani klienci' },
      ],
      issues: [],
    })
    t.reason = undefined
    event(t, 'admin', 'Administrator', `Plan v${version} ustawiony ręcznie (zakres właściciela zgłoszenia)`)
    audit('Administrator', 'Ręczne ustawienie parametrów', t.id)
    t.status = 'queued'
    setTimeout(() => complete(t, salesFileFor(t, monthLabel, from, to)), 900)
    return t
  })
}

export function adminRetryInterpretation(id: string) {
  return wait(() => {
    const t = ticket(id)
    if (t.status !== 'needs_intervention') throw new ApiError('conflict', 'Zgłoszenie nie wymaga już interwencji.')
    t.status = 'interpreting'
    t.reason = undefined
    event(t, 'admin', 'Administrator', 'Ponowienie interpretacji')
    audit('Administrator', 'Ponowienie interpretacji', t.id)
    setTimeout(() => {
      // Walkthrough: the provider fails again, the ticket is kept for manual handling (AC-6).
      t.status = 'needs_intervention'
      t.reason = 'Błąd dostawcy AI — usługa niedostępna (HTTP 503)'
      event(t, 'system', 'Interpretacja', 'Dostawca AI niedostępny; zgłoszenie zachowane do obsługi ręcznej')
    }, 1500)
    return t
  }, 300)
}

export function adminChooseCustomer(id: string, customer: string) {
  return wait(() => {
    const t = ticket(id)
    if (!t.candidates?.includes(customer)) throw new ApiError('forbidden', 'Klient spoza zakresu właściciela zgłoszenia.')
    const version = (t.plans[t.plans.length - 1]?.version ?? 0) + 1
    t.plans.push({
      version,
      author: 'admin',
      at: now(),
      operation: 'customer_discount_simulation',
      params: [
        { label: 'Klient', value: customer },
        { label: 'Okres', value: '01–31.10.2026' },
        { label: 'Polityka', value: 'DEMO-RABAT 2026.1 (fikcyjna)' },
      ],
      issues: [],
    })
    t.reason = undefined
    event(t, 'admin', 'Administrator', `Plan v${version}: wybrano klienta „${customer}”`)
    audit('Administrator', 'Wybór klienta w niejednoznacznym zgłoszeniu', t.id)
    t.status = 'queued'
    setTimeout(
      () => complete(t, { ...discountFile(t.id, now()), id: newId(), scopeLabel: customer, name: 'symulacja_rabatu_2026-10.csv' }),
      900,
    )
    return t
  })
}

export function adminReject(id: string) {
  return wait(() => {
    const t = ticket(id)
    t.status = 'rejected'
    t.reason = 'Odrzucone przez administratora'
    event(t, 'admin', 'Administrator', 'Zgłoszenie odrzucone; pracownik otrzyma informację z szablonu')
    audit('Administrator', 'Odrzucenie zgłoszenia', t.id)
    return t
  })
}

// ---- employee ----------------------------------------------------------

export function myFiles() {
  return wait(() => {
    failIfConfigured()
    if (proto.emptyEmployee) return []
    const owner = viewer().owner
    return db.files.filter((f) => f.ownerId === owner).sort((a, b) => b.createdAt.localeCompare(a.createdAt))
  })
}

export function myPendingTickets() {
  return wait(() => {
    if (proto.emptyEmployee) return []
    const owner = viewer().owner
    return db.tickets.filter((t) => t.ownerId === owner && (t.status === 'awaiting_confirmation' || t.status === 'needs_clarification'))
  })
}

export function confirmTicket(id: string) {
  return wait(() => {
    const t = ticket(id)
    if (t.ownerId !== viewer().owner) throw new ApiError('forbidden', 'To zgłoszenie nie należy do Ciebie.')
    if (t.status !== 'awaiting_confirmation') throw new ApiError('conflict', 'Zgłoszenie zostało już obsłużone.')
    event(t, 'employee', 'Pracownik', 'Prośba potwierdzona po zalogowaniu')
    t.status = 'queued'
    setTimeout(() => complete(t, discountFile(t.id, now())), 700)
    return t
  })
}

export function cancelTicket(id: string) {
  return wait(() => {
    const t = ticket(id)
    if (t.ownerId !== viewer().owner) throw new ApiError('forbidden', 'To zgłoszenie nie należy do Ciebie.')
    t.status = 'rejected'
    t.reason = 'Anulowane przez pracownika'
    event(t, 'employee', 'Pracownik', 'Prośba anulowana — „to nie ja”')
    return t
  })
}
