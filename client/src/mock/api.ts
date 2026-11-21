// PROTOTYPE: in-memory mocked API with artificial latency and switchable failures.
// It imitates backend rules (scope, versioned plans, single execution) only for the walkthrough;
// none of this is a security boundary. Replace with the real API client in increment 2.
import { reactive, watch } from 'vue'
import { buildFixtures, discountFile, DEMO_REP_ID, NOW, outMsg } from './data'
import type { Account, ReportFile, Ticket, TicketEvent } from './types'

export type Mode = 'demo' | 'production'
export type Session = { role: 'admin' | 'employee'; accountId: string; name: string } | null

const fixtures = buildFixtures()

export const proto = reactive({
  mode: 'demo' as Mode,
  latencyMs: 450,
  failLists: false,
  emptyEmployee: false,
  session: readSession(),
})

// Prototype stand-in for the real HttpOnly session cookie: survives reloads within the tab only.
function readSession(): Session {
  try {
    const raw = sessionStorage.getItem('proto-session')
    return raw ? (JSON.parse(raw) as Session) : null
  } catch {
    return null
  }
}
watch(() => proto.session, (s) => {
  try {
    if (s) sessionStorage.setItem('proto-session', JSON.stringify(s))
    else sessionStorage.removeItem('proto-session')
  } catch {
    /* storage unavailable: session stays in memory */
  }
})

const db = reactive({
  tickets: fixtures.tickets,
  files: fixtures.files,
  accounts: fixtures.accounts,
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
  constructor(public code: 'network' | 'forbidden' | 'not_found' | 'conflict', message: string) {
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

// ---- auth -------------------------------------------------------------

export const DEMO_CREDENTIALS = {
  employee: { email: 'handlowiec@demo.tabularius', password: 'demo-handlowiec' },
  admin: { email: 'admin@demo.tabularius', password: 'demo-admin' },
}

export function login(role: 'admin' | 'employee', email: string, password: string) {
  return wait(() => {
    const c = DEMO_CREDENTIALS[role]
    if (email.trim().toLowerCase() !== c.email || password !== c.password) {
      throw new ApiError('forbidden', 'Nieprawidłowy e-mail lub hasło.')
    }
    proto.session = role === 'admin'
      ? { role, accountId: 'acc-admin', name: 'Administrator' }
      : { role, accountId: DEMO_REP_ID, name: 'Handlowiec demonstracyjny' }
    return proto.session
  }, 600)
}

export function logout() {
  proto.session = null
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
    const s = proto.session
    if (!s || (s.role === 'employee' && t.ownerId !== s.accountId)) throw new ApiError('forbidden', 'Nie masz dostępu do tego zgłoszenia.')
    return t
  })
}

export function getFile(id: string) {
  return wait(() => {
    const f = db.files.find((x) => x.id === id)
    if (!f) throw new ApiError('not_found', 'Nie znaleziono pliku.')
    const s = proto.session
    // ACC-FILE-1 imitation: owner or administrator only.
    if (!s || (s.role === 'employee' && f.ownerId !== s.accountId)) {
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

export function listAccounts() {
  return wait(() => {
    failIfConfigured()
    return db.accounts
  })
}

export function listAudit() {
  return wait(() => {
    failIfConfigured()
    return db.audit
  })
}

export function createAccount(name: string, email: string, repCode: string) {
  return wait(() => {
    if (db.accounts.some((a) => a.email.toLowerCase() === email.toLowerCase())) {
      throw new ApiError('conflict', 'Konto z tym adresem już istnieje.')
    }
    if (db.accounts.some((a) => a.repCode === repCode)) {
      throw new ApiError('conflict', 'Ten handlowiec ma już konto.')
    }
    const acc: Account = { id: `acc-${db.accounts.length + 1}`, name, email, repCode, customers: 4, status: 'active', lastLogin: null }
    db.accounts.push(acc)
    audit('Administrator', 'Utworzenie konta (hasło wysłane do pracownika' + (proto.mode === 'demo' ? ', w demo przechwycone' : '') + ')', `${name} (${repCode})`)
    return acc
  })
}

export function setAccountStatus(id: string, status: Account['status']) {
  return wait(() => {
    const a = db.accounts.find((x) => x.id === id)
    if (!a) throw new ApiError('not_found', 'Nie znaleziono konta.')
    if (a.shared) throw new ApiError('forbidden', 'Wspólnego konta demonstracyjnego nie można zablokować.')
    a.status = status
    audit('Administrator', status === 'blocked' ? 'Zablokowanie konta (sesje unieważnione)' : 'Odblokowanie konta', `${a.name} (${a.repCode})`)
    return a
  })
}

export function deleteAccount(id: string) {
  return wait(() => {
    const a = db.accounts.find((x) => x.id === id)
    if (!a) throw new ApiError('not_found', 'Nie znaleziono konta.')
    if (a.shared) throw new ApiError('forbidden', 'Wspólnego konta demonstracyjnego nie można usunąć.')
    db.accounts.splice(db.accounts.indexOf(a), 1)
    audit('Administrator', 'Usunięcie konta (historia zachowana)', `${a.repCode}`)
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
    t.messages.push(outMsg(now(), 'notification', 'report_ready', `Raport jest gotowy. Zaloguj się, aby go pobrać: https://tabularius.gitpush.app/moje-raporty/${file.id}`))
    event(t, 'system', 'Poczta', 'Powiadomienie przyjęte przez serwer poczty')
  }, 1600)
}

function salesFileFor(t: Ticket, period: string, from: string, to: string): ReportFile {
  const base = db.files.find((f) => f.id === 'f-7c1e9a')!
  const id = `f-${Math.random().toString(16).slice(2, 8)}`
  const created = now()
  return {
    ...base,
    id,
    ticketId: t.id,
    name: `sprzedaz_${from}_${to}.csv`,
    periodLabel: period,
    createdAt: created,
    expiresAt: new Date(new Date(created).getTime() + 7 * 86400000).toISOString(),
    rows: base.rows.map((r) => (r.cells[2] === 'Okres' ? { ...r, cells: r.cells.map((c, i) => (i === 4 ? `${from} – ${to} (Europe/Warsaw)` : c)) } : r)),
  }
}

/** Simulates a correlated reply in the thread of T-1040 (demo walkthrough of TKT-CLAR / TKT-VER). */
export function simulateReply(id: string, text: string) {
  return wait(() => {
    const t = ticket(id)
    if (t.status !== 'needs_clarification') throw new ApiError('conflict', 'Zgłoszenie nie czeka już na odpowiedź.')
    t.messages.push({ id: `m-${Date.now()}`, direction: 'in', at: now(), kind: 'reply', body: text })
    event(t, 'system', 'System', 'Odpowiedź skorelowana z wątkiem (In-Reply-To + uczestnik)')
    t.status = 'interpreting'
    setTimeout(() => {
      const prev = t.plans[t.plans.length - 1]!
      t.plans.push({
        version: prev.version + 1, author: 'model', at: now(), operation: 'sales_report',
        params: [{ label: 'Okres', value: '1–31 października 2026 („za październik”)' }, prev.params[1]!], issues: [],
      })
      event(t, 'model', 'Interpretacja', `Plan v${prev.version + 1}: uzupełniono okres; zakres zachowany`)
      t.status = 'queued'
      event(t, 'system', 'System', `Plan v${prev.version + 1} w kolejce (jedno wykonanie na wersję)`)
      setTimeout(() => complete(t, salesFileFor(t, '1–31 października 2026', '2026-10-01', '2026-10-31')), 900)
    }, 1300)
    return t
  })
}

/** Admin sets parameters manually after a provider error (CP-005). */
export function adminResolveSales(id: string, monthLabel: string, from: string, to: string) {
  return wait(() => {
    const t = ticket(id)
    if (t.status !== 'needs_intervention') throw new ApiError('conflict', 'Zgłoszenie nie wymaga już interwencji.')
    const version = (t.plans[t.plans.length - 1]?.version ?? 0) + 1
    t.plans.push({ version, author: 'admin', at: now(), operation: 'sales_report', params: [
      { label: 'Okres', value: monthLabel }, { label: 'Zakres', value: 'Przypisani klienci właściciela (6)' }], issues: [] })
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
    t.plans.push({ version, author: 'admin', at: now(), operation: 'customer_discount_simulation', params: [
      { label: 'Klient', value: customer }, { label: 'Okres', value: '1–31 października 2026' }, { label: 'Polityka', value: 'DEMO-RABAT 2026.1 (fikcyjna)' }], issues: [] })
    t.reason = undefined
    event(t, 'admin', 'Administrator', `Plan v${version}: wybrano klienta „${customer}”`)
    audit('Administrator', 'Wybór klienta w niejednoznacznym zgłoszeniu', t.id)
    t.status = 'queued'
    setTimeout(() => complete(t, { ...discountFile(t.id, now()), id: `f-${Math.random().toString(16).slice(2, 8)}`, scopeLabel: customer, name: 'symulacja_rabatu_2026-10.csv' }), 900)
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
    const s = proto.session
    return db.files.filter((f) => f.ownerId === s?.accountId).sort((a, b) => b.createdAt.localeCompare(a.createdAt))
  })
}

export function myPendingTickets() {
  return wait(() => {
    if (proto.emptyEmployee) return []
    const s = proto.session
    return db.tickets.filter((t) => t.ownerId === s?.accountId && (t.status === 'awaiting_confirmation' || t.status === 'needs_clarification'))
  })
}

export function confirmTicket(id: string) {
  return wait(() => {
    const t = ticket(id)
    if (t.ownerId !== proto.session?.accountId) throw new ApiError('forbidden', 'To zgłoszenie nie należy do Ciebie.')
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
    if (t.ownerId !== proto.session?.accountId) throw new ApiError('forbidden', 'To zgłoszenie nie należy do Ciebie.')
    t.status = 'rejected'
    t.reason = 'Anulowane przez pracownika'
    event(t, 'employee', 'Pracownik', 'Prośba anulowana — „to nie ja”')
    return t
  })
}
