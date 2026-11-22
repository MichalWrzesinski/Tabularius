import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ReportFile } from '../mock/types'
import { csvSizeLabel, downloadCsv } from './download'

const file: ReportFile = {
  id: 'f-1',
  ticketId: 'T-1',
  ownerId: 'a',
  name: 'sprzedaz_2026-10.csv',
  operation: 'sales_report',
  periodLabel: '01–31.10.2026',
  scopeLabel: 'Twoi klienci',
  createdAt: '2026-11-15T09:00:00Z',
  expiresAt: '2026-11-22T09:00:00Z',
  header: ['a', 'b'],
  rows: [{ type: 'detail', cells: ['x', '1,00'] }],
}

afterEach(() => vi.restoreAllMocks())

describe('download', () => {
  it('labels the CSV size in KB (at least 1)', () => {
    expect(csvSizeLabel(file)).toBe('CSV · 1 KB')
  })

  it('downloads the CSV built from the file rows under its name', async () => {
    let blob: Blob | undefined
    const create = vi.fn((b: Blob) => {
      blob = b
      return 'blob:test'
    })
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: vi.fn() })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    downloadCsv(file)

    expect(click).toHaveBeenCalledOnce()
    expect(create).toHaveBeenCalledOnce()
    expect(blob?.type).toBe('text/csv;charset=utf-8')
    const bytes = new Uint8Array(await blob!.arrayBuffer())
    expect([...bytes.slice(0, 3)]).toEqual([0xef, 0xbb, 0xbf])
    expect(new TextDecoder().decode(bytes.slice(3))).toBe('a;b\r\nx;1,00\r\n')
  })
})
