import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  actionLabel,
  blockAccount,
  createAccount,
  deleteAccount,
  listAccounts,
  listAudit,
  listSalesReps,
  refLabel,
  unblockAccount,
} from './accounts'

afterEach(() => vi.unstubAllGlobals())

describe('accounts API', () => {
  it('calls the admin endpoints', async () => {
    const fetch = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>(
      async () => new Response(JSON.stringify({ items: [] }), { status: 200 }),
    )
    vi.stubGlobal('fetch', fetch)

    await listAccounts()
    await listSalesReps()
    await listAudit()
    await createAccount('A', 'a@example.com', 'PH-02')
    await blockAccount('id/1')
    await unblockAccount('id')
    await deleteAccount('id')

    expect(fetch.mock.calls.map((c) => `${(c[1] as RequestInit).method} ${c[0]}`)).toEqual([
      'GET /api/v1/admin/accounts',
      'GET /api/v1/admin/sales-reps',
      'GET /api/v1/admin/audit',
      'POST /api/v1/admin/accounts',
      'POST /api/v1/admin/accounts/id%2F1/block',
      'POST /api/v1/admin/accounts/id/unblock',
      'DELETE /api/v1/admin/accounts/id',
    ])
  })
})

describe('audit labels', () => {
  it('translates actions and pseudonymous references', () => {
    expect(actionLabel('account.blocked')).toBe('Zablokowanie konta (sesje unieważnione)')
    expect(actionLabel('custom.action')).toBe('custom.action')
    const names = new Map([['abc', 'Anna']])
    expect(refLabel('account:abc', names)).toBe('Anna')
    expect(refLabel('account:0123456789', names)).toBe('konto 01234567…')
    expect(refLabel('system', names)).toBe('system')
    expect(refLabel('ticket:0123456789', names)).toBe('zgłoszenie 01234567…')
    expect(refLabel('file:0123456789', names)).toBe('plik 01234567…')
    expect(actionLabel('security.request_refused')).toContain('poza zakresem')
    expect(refLabel('self', names)).toBe('samoobsługa')
    expect(refLabel('other:T-1', names)).toBe('other:T-1')
  })
})
