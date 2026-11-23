// Tickets and report files on the real API (TASK-005). Downloads go through the authorized endpoint (ACC-FILE-1).
import { api, ApiError } from './http'
import type { Catalog, CustomerRef, FileMeta, MyTicket, Operation, ReportPreview, Ticket, TicketSummary } from './types'

const items = <T>(r: { items: T[] }) => r.items

export const listTickets = () => api<{ items: TicketSummary[] }>('GET', '/admin/tickets').then(items)
export const getTicket = (id: string) => api<Ticket>('GET', `/admin/tickets/${encodeURIComponent(id)}`)
export const createTicket = (ownerId: string, operation: Operation, parameters: Record<string, unknown>) =>
  api<Ticket>('POST', '/admin/tickets', { ownerId, operation, parameters })
export const setTicketPlan = (id: string, operation: Operation, parameters: Record<string, unknown>) =>
  api<Ticket>('POST', `/admin/tickets/${encodeURIComponent(id)}/plan`, { operation, parameters })
export const retryTicket = (id: string) => api<Ticket>('POST', `/admin/tickets/${encodeURIComponent(id)}/retry`)
export const resolveNotification = (id: string, notification: string, resolution: 'sent' | 'resend') =>
  api<Ticket>('POST', `/admin/tickets/${encodeURIComponent(id)}/notifications/${encodeURIComponent(notification)}/resolve`, { resolution })
export const rejectTicket = (id: string) => api<Ticket>('POST', `/admin/tickets/${encodeURIComponent(id)}/reject`)
export const getCatalog = () => api<Catalog>('GET', '/admin/catalog')
export const ownerCustomers = (accountId: string) =>
  api<{ items: CustomerRef[] }>('GET', `/admin/accounts/${encodeURIComponent(accountId)}/customers`).then(items)

export const listFiles = () => api<{ items: FileMeta[] }>('GET', '/admin/files').then(items)
export const myFiles = () => api<{ items: FileMeta[] }>('GET', '/me/files').then(items)
export const myTickets = () =>
  api<{ items: Pick<TicketSummary, 'id' | 'number' | 'receivedAt' | 'status' | 'operation' | 'operationLabel'>[] }>(
    'GET',
    '/me/tickets',
  ).then(items)
export const getMyTicket = (id: string) => api<MyTicket>('GET', `/me/tickets/${encodeURIComponent(id)}`)
export const confirmMyTicket = (id: string) => api<MyTicket>('POST', `/me/tickets/${encodeURIComponent(id)}/confirm`)
export const cancelMyTicket = (id: string) => api<MyTicket>('POST', `/me/tickets/${encodeURIComponent(id)}/cancel`)
export const getFile = (id: string) => api<ReportPreview>('GET', `/files/${encodeURIComponent(id)}`)

/** Fetches the CSV through the authorized endpoint and saves it; refusals surface as ApiError (no blind navigation). */
export async function downloadFile(file: Pick<FileMeta, 'id' | 'name'>): Promise<void> {
  let response: Response
  try {
    response = await fetch(`/api/v1/files/${encodeURIComponent(file.id)}/content`, { credentials: 'same-origin' })
  } catch {
    throw new ApiError(0, 'network', 'Brak połączenia z serwerem. Sprawdź połączenie i spróbuj ponownie.')
  }
  if (!response.ok) {
    const problem = (await response.json().catch(() => ({}))) as { code?: unknown }
    throw new ApiError(response.status, typeof problem.code === 'string' ? problem.code : 'http_error', `HTTP ${response.status}`)
  }
  const url = URL.createObjectURL(await response.blob())
  const a = document.createElement('a')
  a.href = url
  a.download = file.name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export function sizeLabel(file: Pick<FileMeta, 'sizeBytes'>): string {
  return `CSV · ${Math.max(1, Math.round(file.sizeBytes / 1024))} KB`
}

/** Polish explanation for a ticket's reason code. */
export const REASON_LABEL: Record<string, string> = {
  data_source_error: 'Błąd źródła danych — system danych był niedostępny',
  internal_error: 'Błąd techniczny podczas generowania raportu',
  out_of_scope: 'Zakres klientów pracownika nie obejmuje żądanych danych',
  invalid_parameters: 'Parametry planu są nieprawidłowe',
  too_large: 'Raport przekracza limit wierszy',
  owner_inactive: 'Konto pracownika jest nieaktywne',
  admin_rejected: 'Odrzucone przez administratora',
  message_too_large: 'Wiadomość przekracza limit rozmiaru',
  message_unparseable: 'Nie udało się odczytać wiadomości',
  message_empty: 'Wiadomość nie zawiera tekstu',
  text_too_long: 'Tekst prośby jest za długi',
  identity_unverified: 'Nadawca nierozpoznany — tożsamość niepotwierdzona',
  cancelled_by_owner: 'Anulowane przez pracownika („to nie ja”)',
  confirmation_expired: 'Wygasło bez potwierdzenia pracownika',
  clarification_needed: 'Prośba wymaga uzupełnienia parametrów',
  clarification_exhausted: 'Po 3 pytaniach prośba nadal niepełna',
  clarification_expired: 'Brak odpowiedzi na pytanie w ciągu 7 dni',
  out_of_catalog: 'Prośba spoza katalogu operacji',
  interpretation_unavailable: 'Interpretacja niemożliwa — ustaw parametry ręcznie',
  outside_business_scope: 'Prośba poza zakresem usługi (kontrola bezpieczeństwa)',
  model_output_rejected: 'Wynik interpretacji odrzucony przez kontrolę zakresu',
  ai_provider_error: 'Błąd dostawcy AI — ustaw parametry ręcznie',
  ai_limit: 'Limit interpretacji AI wyczerpany — ustaw parametry ręcznie',
  processing_failed: 'Zadanie nie powiodło się po ponowieniach (błąd techniczny)',
  processing_stalled: 'Przetwarzanie bez postępu przez ponad 24 godziny',
}

export const reasonLabel = (code: string | null | undefined) => (code ? (REASON_LABEL[code] ?? code) : '')

export const TEMPLATE_LABEL: Record<string, string> = {
  received: 'Potwierdzenie przyjęcia',
  confirmation_request: 'Prośba o potwierdzenie',
  report_ready: 'Raport gotowy',
  refused: 'Odmowa z szablonu',
  clarification: 'Pytanie o uzupełnienie',
}

/** Delivery state of a notification; „przyjęte” means accepted by the mail server, not delivered. */
export function deliveryLabel(delivery: string, route: string): string {
  if (delivery === 'accepted') return route === 'capture' ? 'przechwycone (demo przed uruchomieniem)' : 'przyjęte przez serwer poczty'
  return (
    {
      pending: 'oczekuje na wysłanie',
      sending: 'wysyłanie',
      unknown: 'wynik nieznany',
      failed: 'nie wysłano',
      bounced: 'zwrot — nie doręczono',
    }[delivery] ?? delivery
  )
}

/** Reasons the administrator can fix by re-running the same plan version. */
export const RETRYABLE = new Set(['data_source_error', 'internal_error', 'processing_failed', 'processing_stalled'])

/** Delivery states the administrator resolves by hand (never resent automatically): which decisions each allows. */
export function notificationResolutions(delivery: string): Array<'sent' | 'resend'> {
  if (delivery === 'unknown') return ['sent', 'resend']
  return delivery === 'failed' ? ['resend'] : []
}
