import { describe, expect, it } from 'vitest'
import { statusLabel } from './status'

describe('statusLabel', () => {
  it('reads „Błąd” only for technical interventions', () => {
    expect(statusLabel('needs_intervention', 'Błąd dostawcy AI — brak odpowiedzi')).toBe('Błąd')
    expect(statusLabel('needs_intervention', 'Limit pytań wyczerpany')).toBe('Do sprawdzenia')
    expect(statusLabel('completed')).toBe('Ukończone')
  })
})
