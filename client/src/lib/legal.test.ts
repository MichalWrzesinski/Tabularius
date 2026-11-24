import { describe, expect, it } from 'vitest'
import { LEGAL_DOCUMENTS, PRIVACY, TERMS, placeholderParts } from './legal'

describe('legal documents', () => {
  it('have stable paths and mark every unknown as a placeholder', () => {
    expect(LEGAL_DOCUMENTS.map((d) => d.path)).toEqual(['/regulamin', '/polityka-prywatnosci'])
    for (const doc of [PRIVACY, TERMS]) {
      const text = doc.sections.flatMap((s) => [...(s.paragraphs ?? []), ...(s.items ?? [])]).join(' ')
      expect(text).toContain('[do uzupełnienia:')
      expect(doc.version).toContain('wersja robocza')
    }
  })

  it('states the retention periods the application enforces', () => {
    const text = JSON.stringify(PRIVACY)
    expect(text).toContain('7 dni')
    expect(text).toContain('90 dni')
    expect(text).toContain('store: false')
  })

  it('splits placeholders from plain text', () => {
    expect(placeholderParts('A [do uzupełnienia: x] B')).toEqual([
      { text: 'A ', placeholder: false },
      { text: '[do uzupełnienia: x]', placeholder: true },
      { text: ' B', placeholder: false },
    ])
    expect(placeholderParts('plain')).toEqual([{ text: 'plain', placeholder: false }])
  })
})
