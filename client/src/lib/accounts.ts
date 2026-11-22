// Administrator account management (TASK-004, ACC-LIFE) on the real API.
import { api } from './http'

export interface AdminAccount {
  id: string
  name: string
  email: string
  role: 'admin' | 'rep'
  repCode: string | null
  status: 'active' | 'blocked'
  shared: boolean
  lastLoginAt: string | null
}

export interface SalesRepOption {
  code: string
  taken: boolean
}

export interface AuditEntry {
  at: string
  actorRef: string
  action: string
  targetRef: string
}

export const listAccounts = () => api<{ items: AdminAccount[] }>('GET', '/admin/accounts').then((r) => r.items)
export const listSalesReps = () => api<{ items: SalesRepOption[] }>('GET', '/admin/sales-reps').then((r) => r.items)
export const createAccount = (name: string, email: string, repCode: string) =>
  api<AdminAccount>('POST', '/admin/accounts', { name, email, repCode })
export const blockAccount = (id: string) => api<AdminAccount>('POST', `/admin/accounts/${encodeURIComponent(id)}/block`)
export const unblockAccount = (id: string) => api<AdminAccount>('POST', `/admin/accounts/${encodeURIComponent(id)}/unblock`)
export const deleteAccount = (id: string) => api<void>('DELETE', `/admin/accounts/${encodeURIComponent(id)}`)
export const listAudit = () => api<{ items: AuditEntry[] }>('GET', '/admin/audit').then((r) => r.items)

const ACTIONS: Record<string, string> = {
  'auth.login': 'Logowanie',
  'account.created': 'Utworzenie konta (hasło wysłane do pracownika)',
  'account.blocked': 'Zablokowanie konta (sesje unieważnione)',
  'account.unblocked': 'Odblokowanie konta',
  'account.deleted': 'Usunięcie konta (historia zachowana)',
  'account.password_changed': 'Zmiana hasła',
  'account.password_reset': 'Reset hasła',
  'ticket.create': 'Utworzenie zgłoszenia ręcznego',
  'ticket.plan': 'Ustawienie parametrów zgłoszenia',
  'ticket.retry': 'Ponowienie wykonania',
  'ticket.reject': 'Odrzucenie zgłoszenia',
  'ticket.confirm': 'Potwierdzenie prośby przez pracownika',
  'ticket.cancel': 'Anulowanie prośby przez pracownika',
  'file.download': 'Pobranie raportu',
  'security.request_refused': 'Bezpieczeństwo: prośba poza zakresem usługi odrzucona',
  'security.plan_rejected': 'Bezpieczeństwo: wynik interpretacji odrzucony',
  'security.scope_refused': 'Bezpieczeństwo: prośba o dane spoza zakresu odrzucona',
}

export function actionLabel(action: string): string {
  return ACTIONS[action] ?? action
}

/** Pseudonymous reference shown in the audit view: „konto 01a202de…”. */
export function refLabel(ref: string, names: Map<string, string>): string {
  if (ref === 'self') return 'samoobsługa'
  if (ref === 'system') return 'system'
  const [kind, id] = ref.split(':', 2)
  if (kind === 'account' && id) return names.get(id) ?? `konto ${id.slice(0, 8)}…`
  if (kind === 'ticket' && id) return `zgłoszenie ${id.slice(0, 8)}…`
  if (kind === 'file' && id) return `plik ${id.slice(0, 8)}…`
  return ref
}
