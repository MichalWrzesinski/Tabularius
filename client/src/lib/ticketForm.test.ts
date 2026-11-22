import { describe, expect, it } from 'vitest'
import { emptyForm, formProblem, parametersOf, periodOf } from './ticketForm'

describe('manual ticket form', () => {
  it('builds period expressions of the closed set', () => {
    const f = emptyForm()
    expect(periodOf(f)).toEqual({ type: 'previous_month' })
    expect(periodOf({ ...f, periodType: 'month', month: 9 })).toEqual({ type: 'month', month: 9 })
    expect(periodOf({ ...f, periodType: 'month', month: 9, year: 2025 })).toEqual({ type: 'month', month: 9, year: 2025 })
    expect(periodOf({ ...f, periodType: 'quarter', quarter: 2 })).toEqual({ type: 'quarter', quarter: 2 })
    expect(periodOf({ ...f, periodType: 'last_n_days', days: 30 })).toEqual({ type: 'last_n_days', n: 30 })
    expect(periodOf({ ...f, periodType: 'explicit', start: '2026-10-01', end: '2026-10-15' })).toEqual({
      type: 'explicit',
      start: '2026-10-01',
      end: '2026-10-15',
    })
  })

  it('builds parameters per operation, without fields of other operations', () => {
    const f = { ...emptyForm(), manufacturer: 'BOSCH', customer: 'K-004' }
    expect(parametersOf({ ...f, manufacturer: '' })).toEqual({ period: { type: 'previous_month' }, customers: 'all_assigned' })
    expect(parametersOf({ ...f, scope: 'selected', customers: ['K-001'], category: 'Hamulce' })).toEqual({
      period: { type: 'previous_month' },
      customers: ['K-001'],
      manufacturer: 'BOSCH',
      category: 'Hamulce',
    })
    expect(parametersOf({ ...f, operation: 'parts_catalog' })).toEqual({ manufacturer: 'BOSCH' })
    expect(parametersOf({ ...f, operation: 'customer_discount_simulation' })).toEqual({
      customer: 'K-004',
      period: { type: 'previous_month' },
    })
  })

  it('names what is missing before sending', () => {
    const f = emptyForm()
    expect(formProblem(f, '')).toContain('pracownika')
    expect(formProblem(f, 'id')).toBeNull()
    expect(formProblem({ ...f, operation: 'parts_catalog' }, 'id')).toContain('producenta')
    expect(formProblem({ ...f, operation: 'parts_catalog', manufacturer: 'BOSCH' }, 'id')).toBeNull()
    expect(formProblem({ ...f, periodType: 'explicit' }, 'id')).toContain('okresu')
    expect(formProblem({ ...f, operation: 'customer_discount_simulation' }, 'id')).toContain('klienta')
    expect(formProblem({ ...f, scope: 'selected' }, 'id')).toContain('klienta')
  })
})
