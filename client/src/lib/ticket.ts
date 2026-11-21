import { proto } from '../mock/api'
import type { Ticket } from '../mock/types'

const MONTH_NOM = [
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

/** „01–31.10.2026” → „październik” when the period is one full calendar month. */
function fullMonth(period: string): string | null {
  const m = /^01–(\d{2})\.(\d{2})\.(\d{4})$/.exec(period)
  if (!m) return null
  const [, d, mm, y] = m
  const last = new Date(Date.UTC(Number(y), Number(mm), 0)).getUTCDate()
  return Number(d) === last ? (MONTH_NOM[Number(mm) - 1] ?? null) : null
}

/** Short title in the mockups' style, built from the recognized operation and parameters — never from the subject. */
export function ticketTitle(t: Ticket): string {
  const plan = t.plans[t.plans.length - 1]
  if (!plan) return t.status === 'received' || t.status === 'interpreting' ? 'Nowa prośba' : 'Prośba nierozpoznana'
  const param = (label: string) => plan.params.find((p) => p.label === label)?.value ?? ''
  switch (plan.operation) {
    case 'sales_report': {
      const month = fullMonth(param('Okres'))
      return month ? `Sprzedaż za ${month}` : 'Raport sprzedaży'
    }
    case 'parts_catalog':
      return param('Producent') ? `Katalog części ${param('Producent')}` : 'Katalog części'
    case 'customer_discount_simulation':
      return 'Symulacja rabatu'
    default:
      return plan.issues.includes('out_of_catalog') ? 'Prośba spoza katalogu' : 'Prośba nierozpoznana'
  }
}

/** Production: employee name. Public demo: synthetic alias only (ID-DEMO-3). */
export function ticketPerson(t: Ticket): string {
  return proto.anonymize ? t.participantAlias : t.employeeName
}

export function initials(name: string): string {
  return name
    .split(/\s+/)
    .map((x) => x[0] ?? '')
    .join('')
    .slice(0, 2)
    .toUpperCase()
}
