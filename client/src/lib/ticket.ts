import { proto } from '../mock/api'
import type { Ticket } from '../mock/types'
import { OPERATION_LABEL } from './status'

/** Title generated from the recognized operation and resolved parameters — never from the subject or body. */
export function ticketTitle(t: Ticket): string {
  const plan = t.plans[t.plans.length - 1]
  if (!plan) return t.status === 'received' || t.status === 'interpreting' ? 'Prośba w trakcie rozpoznania' : 'Prośba bez rozpoznanej operacji'
  if (!plan.operation) return plan.issues.includes('out_of_catalog') ? 'Prośba spoza katalogu' : 'Prośba nierozpoznana'
  const main = plan.params.find((p) => p.label === 'Okres' || p.label === 'Producent' || p.label === 'Klient')
  const value = main && !main.value.startsWith('—') ? main.value.replace(/ \(.*\)$/, '').replace(/^„.*$/, '') : ''
  return value ? `${OPERATION_LABEL[plan.operation]} · ${value}` : OPERATION_LABEL[plan.operation]
}

/** Who is shown: employee in production, synthetic alias in the public demo (ID-DEMO-3). */
export function ticketPerson(t: Ticket): string {
  return proto.mode === 'demo' ? t.participantAlias : t.employeeName
}
