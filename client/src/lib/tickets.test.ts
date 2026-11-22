import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from './http'
import {
  TEMPLATE_LABEL,
  cancelMyTicket,
  deliveryLabel,
  confirmMyTicket,
  createTicket,
  getMyTicket,
  setTicketPlan,
  downloadFile,
  getCatalog,
  getFile,
  getTicket,
  listFiles,
  listTickets,
  myFiles,
  myTickets,
  ownerCustomers,
  reasonLabel,
  rejectTicket,
  retryTicket,
  RETRYABLE,
  sizeLabel,
} from './tickets'

afterEach(() => vi.unstubAllGlobals())

describe('tickets API', () => {
  it('calls the ticket and file endpoints', async () => {
    const fetch = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>(
      async () => new Response(JSON.stringify({ items: [] }), { status: 200 }),
    )
    vi.stubGlobal('fetch', fetch)

    await listTickets()
    await getTicket('a/b')
    await createTicket('owner', 'sales_report', { period: { type: 'previous_month' } })
    await retryTicket('t')
    await rejectTicket('t')
    await getCatalog()
    await ownerCustomers('acc')
    await listFiles()
    await myFiles()
    await myTickets()
    await getFile('f')
    await setTicketPlan('t', 'parts_catalog', { manufacturer: 'BOSCH' })
    await getMyTicket('m')
    await confirmMyTicket('m')
    await cancelMyTicket('m')

    expect(fetch.mock.calls.map(([url, init]) => `${init?.method} ${url}`)).toEqual([
      'GET /api/v1/admin/tickets',
      'GET /api/v1/admin/tickets/a%2Fb',
      'POST /api/v1/admin/tickets',
      'POST /api/v1/admin/tickets/t/retry',
      'POST /api/v1/admin/tickets/t/reject',
      'GET /api/v1/admin/catalog',
      'GET /api/v1/admin/accounts/acc/customers',
      'GET /api/v1/admin/files',
      'GET /api/v1/me/files',
      'GET /api/v1/me/tickets',
      'GET /api/v1/files/f',
      'POST /api/v1/admin/tickets/t/plan',
      'GET /api/v1/me/tickets/m',
      'POST /api/v1/me/tickets/m/confirm',
      'POST /api/v1/me/tickets/m/cancel',
    ])
    expect(JSON.parse(String(fetch.mock.calls[2]?.[1]?.body))).toEqual({
      ownerId: 'owner',
      operation: 'sales_report',
      parameters: { period: { type: 'previous_month' } },
    })
  })

  it('downloads through the authorized endpoint', async () => {
    const fetch = vi.fn(async () => new Response('﻿a;b\r\n', { status: 200 }))
    vi.stubGlobal('fetch', fetch)
    const createObjectURL = vi.fn(() => 'blob:x')
    vi.stubGlobal('URL', { createObjectURL, revokeObjectURL: vi.fn() })
    const click = vi.fn()
    const anchor = { click, remove: vi.fn(), href: '', download: '' }
    vi.stubGlobal('document', { createElement: () => anchor, body: { appendChild: vi.fn() } })

    await downloadFile({ id: 'f1', name: 'sprzedaz.csv' })

    expect(fetch).toHaveBeenCalledWith('/api/v1/files/f1/content', { credentials: 'same-origin' })
    expect(anchor.download).toBe('sprzedaz.csv')
    expect(click).toHaveBeenCalled()
  })

  it('turns refused downloads and network errors into ApiError', async () => {
    vi.stubGlobal('fetch', async () => new Response(JSON.stringify({ code: 'scope_changed' }), { status: 403 }))
    await expect(downloadFile({ id: 'f', name: 'x.csv' })).rejects.toMatchObject({ status: 403, code: 'scope_changed' })

    vi.stubGlobal('fetch', async () => new Response('oops', { status: 500 }))
    await expect(downloadFile({ id: 'f', name: 'x.csv' })).rejects.toMatchObject({ code: 'http_error' })

    vi.stubGlobal('fetch', async () => {
      throw new TypeError('offline')
    })
    await expect(downloadFile({ id: 'f', name: 'x.csv' })).rejects.toBeInstanceOf(ApiError)
  })

  it('labels notifications', () => {
    expect(deliveryLabel('accepted', 'deliver')).toBe('przyjęte przez serwer poczty')
    expect(deliveryLabel('accepted', 'capture')).toContain('przechwycone')
    expect(deliveryLabel('unknown', 'deliver')).toBe('wynik nieznany')
    expect(deliveryLabel('other', 'deliver')).toBe('other')
    expect(TEMPLATE_LABEL.report_ready).toBe('Raport gotowy')
  })

  it('labels sizes and reasons', () => {
    expect(sizeLabel({ sizeBytes: 1679 })).toBe('CSV · 2 KB')
    expect(sizeLabel({ sizeBytes: 10 })).toBe('CSV · 1 KB')
    expect(reasonLabel('data_source_error')).toContain('Błąd źródła danych')
    expect(reasonLabel('text_too_long')).toContain('za długi')
    expect(reasonLabel('other')).toBe('other')
    expect(reasonLabel(null)).toBe('')
    expect(RETRYABLE.has('internal_error')).toBe(true)
  })
})
