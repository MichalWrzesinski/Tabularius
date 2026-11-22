import type { Operation, TicketStatus } from './types'

export const STATUS: Record<TicketStatus, { label: string; tone: 'done' | 'progress' | 'wait' | 'error' | 'neutral' }> = {
  received: { label: 'Przyjęte', tone: 'neutral' },
  interpreting: { label: 'Interpretacja', tone: 'progress' },
  needs_clarification: { label: 'Do uzupełnienia', tone: 'wait' },
  awaiting_confirmation: { label: 'Do potwierdzenia', tone: 'wait' },
  queued: { label: 'W kolejce', tone: 'progress' },
  running: { label: 'W realizacji', tone: 'progress' },
  completed: { label: 'Ukończone', tone: 'done' },
  rejected: { label: 'Odrzucone', tone: 'neutral' },
  needs_intervention: { label: 'Do sprawdzenia', tone: 'error' },
}

export const STATUS_ORDER: TicketStatus[] = [
  'received',
  'interpreting',
  'needs_clarification',
  'awaiting_confirmation',
  'queued',
  'running',
  'completed',
  'rejected',
  'needs_intervention',
]

export const OPERATION_LABEL: Record<Operation, string> = {
  sales_report: 'Raport sprzedaży',
  parts_catalog: 'Katalog części producenta',
  customer_discount_simulation: 'Aktywność klienta i symulacja rabatu',
}

export const ISSUE_LABEL: Record<string, string> = {
  missing_period: 'Brak okresu raportu',
  ambiguous_customer: 'Niejednoznaczny klient',
  unknown_operation: 'Nierozpoznany rodzaj raportu',
  multiple_operations: 'Kilka operacji w jednej wiadomości',
  period_includes_today: 'Okres obejmuje dzień niezakończony',
  out_of_catalog: 'Operacja spoza katalogu',
  out_of_scope_customer: 'Klient poza Twoim zakresem',
  recipient_change_requested: 'Prośba o zmianę odbiorcy (zignorowana)',
}

/** Technical failure codes; the mockup shows „Błąd” for them, other interventions read „Do sprawdzenia”. */
const ERROR_REASONS = new Set(['data_source_error', 'internal_error', 'ai_provider_error', 'ai_limit'])

/** Mockup shows „Błąd” for technical failures; other interventions read „Do sprawdzenia”. Same status. */
export function statusLabel(status: TicketStatus, reason?: string | null): string {
  if (status === 'needs_intervention' && reason && (ERROR_REASONS.has(reason) || /błąd|niedostępn|limit dostawcy/i.test(reason)))
    return 'Błąd'
  return STATUS[status].label
}
