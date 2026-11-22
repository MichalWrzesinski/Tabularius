import { describe, expect, it } from 'vitest'
import type { Ticket } from '../mock/types'
import { initials, ticketPerson, ticketTitle } from './ticket'
import { proto } from '../mock/api'

function ticket(plan: Partial<Ticket['plans'][number]> | null, status: Ticket['status'] = 'completed'): Ticket {
  return {
    id: 'T-1',
    ownerId: 'a',
    employeeName: 'Anna Kowalska',
    employeeEmail: 'a@example.com',
    participantAlias: 'Uczestnik #K7',
    receivedAt: '2026-11-15T09:00:00Z',
    status,
    plans: plan ? [{ version: 1, author: 'model', at: '', operation: null, params: [], issues: [], ...plan }] : [],
    messages: [],
    events: [],
    clarificationRounds: 0,
  }
}

describe('ticketTitle', () => {
  it('names a full-month sales report by its month', () => {
    expect(ticketTitle(ticket({ operation: 'sales_report', params: [{ label: 'Okres', value: '01–31.10.2026' }] }))).toBe(
      'Sprzedaż za październik',
    )
    expect(ticketTitle(ticket({ operation: 'sales_report', params: [{ label: 'Okres', value: '01–28.02.2026' }] }))).toBe(
      'Sprzedaż za luty',
    )
  })
  it('falls back for partial periods and other operations', () => {
    expect(ticketTitle(ticket({ operation: 'sales_report', params: [{ label: 'Okres', value: '08–14.11.2026' }] }))).toBe(
      'Raport sprzedaży',
    )
    expect(ticketTitle(ticket({ operation: 'sales_report', params: [{ label: 'Okres', value: '01–30.10.2026' }] }))).toBe(
      'Raport sprzedaży',
    )
    expect(ticketTitle(ticket({ operation: 'parts_catalog', params: [{ label: 'Producent', value: 'Bosch' }] }))).toBe(
      'Katalog części Bosch',
    )
    expect(ticketTitle(ticket({ operation: 'parts_catalog' }))).toBe('Katalog części')
    expect(ticketTitle(ticket({ operation: 'customer_discount_simulation' }))).toBe('Symulacja rabatu')
    expect(ticketTitle(ticket({ issues: ['out_of_catalog'] }))).toBe('Prośba spoza katalogu')
    expect(ticketTitle(ticket({}))).toBe('Prośba nierozpoznana')
    expect(ticketTitle(ticket(null, 'interpreting'))).toBe('Nowa prośba')
    expect(ticketTitle(ticket(null, 'needs_intervention'))).toBe('Prośba nierozpoznana')
  })
})

describe('ticketPerson', () => {
  it('shows the alias only in public-demo views', () => {
    const t = ticket(null)
    proto.anonymize = false
    expect(ticketPerson(t)).toBe('Anna Kowalska')
    proto.anonymize = true
    expect(ticketPerson(t)).toBe('Uczestnik #K7')
    proto.anonymize = false
  })
  it('builds initials', () => {
    expect(initials('Anna Kowalska')).toBe('AK')
    expect(initials('Administrator')).toBe('AD')
    expect(initials('')).toBe('')
  })
})
