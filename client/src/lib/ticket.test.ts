import { describe, expect, it } from 'vitest'
import { initials, ticketPerson, ticketTitle } from './ticket'
import type { PlanVersion, TicketStatus } from './types'

function ticket(plan: Partial<PlanVersion> | null, status: TicketStatus = 'completed') {
  return {
    status,
    employeeName: 'Uczestnik #K7',
    plans: plan ? [{ version: 1, author: 'model' as const, at: '', operation: null, params: [], issues: [], ...plan }] : [],
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
    expect(ticketTitle(ticket(null, 'received'))).toBe('Nowa prośba')
    expect(ticketTitle(ticket(null, 'needs_intervention'))).toBe('Prośba nierozpoznana')
  })
})

describe('ticketPerson', () => {
  it('shows what the server decided (owner or demo alias)', () => {
    expect(ticketPerson(ticket(null))).toBe('Uczestnik #K7')
  })
  it('builds initials', () => {
    expect(initials('Anna Kowalska')).toBe('AK')
    expect(initials('Administrator')).toBe('AD')
    expect(initials('')).toBe('')
  })
})
