// Manual ticket form → plan parameters in the API's closed schema (PeriodExpression, ReportEngine). The backend
// validates again; this only shapes what the administrator chose.
import type { Operation } from './types'

export type PeriodType = 'previous_month' | 'month' | 'quarter' | 'last_n_days' | 'explicit'

export interface TicketForm {
  operation: Operation
  periodType: PeriodType
  month: number
  quarter: number
  year: number | null
  days: number
  start: string
  end: string
  scope: 'all' | 'selected'
  customers: string[]
  customer: string
  manufacturer: string
  category: string
}

export const PERIOD_OPTIONS: { value: PeriodType; label: string }[] = [
  { value: 'previous_month', label: 'Poprzedni miesiąc' },
  { value: 'month', label: 'Wybrany miesiąc' },
  { value: 'quarter', label: 'Kwartał' },
  { value: 'last_n_days', label: 'Ostatnie dni' },
  { value: 'explicit', label: 'Zakres dat' },
]

export const MONTHS = [
  'styczeń',
  'luty',
  'marzec',
  'kwiecień',
  'maj',
  'czerwiec',
  'lipiec',
  'sierpień',
  'wrzesień',
  'październik',
  'listopad',
  'grudzień',
]

export function emptyForm(): TicketForm {
  return {
    operation: 'sales_report',
    periodType: 'previous_month',
    month: 10,
    quarter: 3,
    year: null,
    days: 7,
    start: '',
    end: '',
    scope: 'all',
    customers: [],
    customer: '',
    manufacturer: '',
    category: '',
  }
}

export function periodOf(f: TicketForm): Record<string, unknown> {
  const year = f.year === null ? {} : { year: f.year }
  switch (f.periodType) {
    case 'month':
      return { type: 'month', month: f.month, ...year }
    case 'quarter':
      return { type: 'quarter', quarter: f.quarter, ...year }
    case 'last_n_days':
      return { type: 'last_n_days', n: f.days }
    case 'explicit':
      return { type: 'explicit', start: f.start, end: f.end }
    default:
      return { type: 'previous_month' }
  }
}

/** Client-side completeness check (Polish message) before sending; null when the form can be sent. */
export function formProblem(f: TicketForm, ownerId: string): string | null {
  if (!ownerId) return 'Wybierz pracownika, w którego imieniu tworzysz zgłoszenie.'
  if (f.operation === 'parts_catalog') return f.manufacturer ? null : 'Wybierz producenta.'
  if (f.periodType === 'explicit' && (!f.start || !f.end)) return 'Podaj początek i koniec okresu.'
  if (f.operation === 'customer_discount_simulation' && !f.customer) return 'Wybierz klienta.'
  if (f.operation === 'sales_report' && f.scope === 'selected' && !f.customers.length) return 'Wybierz co najmniej jednego klienta.'
  return null
}

export function parametersOf(f: TicketForm): Record<string, unknown> {
  switch (f.operation) {
    case 'parts_catalog':
      return { manufacturer: f.manufacturer }
    case 'customer_discount_simulation':
      return { customer: f.customer, period: periodOf(f) }
    default:
      return {
        period: periodOf(f),
        customers: f.scope === 'all' ? 'all_assigned' : [...f.customers],
        ...(f.manufacturer ? { manufacturer: f.manufacturer } : {}),
        ...(f.category ? { category: f.category } : {}),
      }
  }
}
