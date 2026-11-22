// CP-001 without mail and CP-003 file part (docs/qa/critical-paths.md) through the UI against the running stack
// (make up, worker running): admin creates a manual sales ticket → worker generates the file → the shared demo
// representative opens „Moje raporty” on a 360 px phone and downloads the CSV → another representative gets 404.
// Run (from client/): see client/README.md.
import { readFile } from 'node:fs/promises'
import { chromium } from 'playwright'

const BASE = process.env.BASE_URL ?? 'http://host.docker.internal:8080'
const MAILPIT = process.env.MAILPIT_URL ?? 'http://host.docker.internal:8025'
const DEMO_PASSWORD = 'Demo123!' // gitleaks:allow (public demo credential)
const steps = []
const consoleErrors = []

async function passwordFromMailpit(to) {
  for (let i = 0; i < 20; i++) {
    const list = await (await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + to)}`)).json()
    if (list.messages?.length) {
      const msg = await (await fetch(`${MAILPIT}/api/v1/message/${list.messages[0].ID}`)).json()
      return /Hasło: (\S+)/.exec(msg.Text)[1]
    }
    await new Promise((r) => setTimeout(r, 300))
  }
  throw new Error('no password e-mail for ' + to)
}

async function login(page, path, email, password) {
  await page.goto(`${BASE}${path}`)
  await page.getByLabel('E-mail').fill(email)
  await page.getByLabel('Hasło', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Zaloguj się' }).click()
}

const browser = await chromium.launch()
const admin = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage()
admin.on('console', (m) => m.type() === 'error' && consoleErrors.push('admin: ' + m.text()))
await login(admin, '/admin/logowanie', 'admin@example.com', DEMO_PASSWORD)
await admin.waitForURL('**/admin')

await admin.goto(`${BASE}/admin/zgloszenia`)
await admin.getByRole('link', { name: 'Nowe zgłoszenie' }).click()
await admin.getByLabel('Pracownik').selectOption({ label: 'Handlowiec demonstracyjny (PH-01)' })
await admin.getByLabel('Operacja').selectOption('sales_report')
await admin.getByLabel('Okres').selectOption('month')
await admin.getByLabel('Miesiąc').selectOption({ label: 'październik' })
await admin.getByRole('button', { name: 'Utwórz i uruchom' }).click()
await admin.waitForURL(/\/admin\/zgloszenia\/[0-9a-f-]{36}$/)
steps.push('admin created a manual sales ticket (October)')
await admin.getByText('Raport jest gotowy do pobrania.').waitFor({ timeout: 30000 })
const number = (await admin.locator('.tickettitle .id').textContent()).trim()
steps.push(`worker completed ${number}; admin sees the file`)

const repCtx = await browser.newContext({ viewport: { width: 360, height: 780 }, acceptDownloads: true })
const rep = await repCtx.newPage()
rep.on('console', (m) => m.type() === 'error' && consoleErrors.push('rep: ' + m.text()))
await login(rep, '/logowanie', 'handlowiec@example.com', DEMO_PASSWORD)
await rep.waitForURL('**/moje-raporty')
await rep.getByRole('link', { name: /Otwórz raport/ }).first().click()
await rep.waitForURL(/\/moje-raporty\/[0-9a-f-]{36}$/)
const fileId = rep.url().split('/').pop()
await rep.getByText('Raport sprzedaży').first().waitFor()
const overflow = await rep.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
if (overflow > 0) throw new Error(`horizontal scroll at 360 px: ${overflow}px`)
steps.push('representative opened the newest report at 360 px (no horizontal scroll)')

const [download] = await Promise.all([rep.waitForEvent('download'), rep.getByRole('button', { name: 'Pobierz CSV' }).click()])
const csv = await readFile(await download.path(), 'utf8')
if (!csv.startsWith('﻿typ_wiersza;data;dokument;') || !/\r\nsuma_calkowita;(;){9}-?\d+,\d{2}\r\n$/.test(csv)) {
  throw new Error('unexpected CSV: ' + csv.slice(0, 120))
}
if (!/meta;Okres;2026-10-01 – 2026-10-31 \(Europe\/Warsaw\)/.test(csv)) throw new Error('period row missing')
steps.push(`representative downloaded ${download.suggestedFilename()} (BOM, header, October period, grand total)`)

// CP-003: another representative (fresh account, password from Mailpit) gets 404 on the same file.
const email = `e2e.${Date.now()}@example.com`
await admin.goto(`${BASE}/admin/pracownicy`)
await admin.getByRole('button', { name: 'Dodaj pracownika' }).click()
await admin.getByLabel('Imię i nazwisko').fill('Test E2E')
await admin.getByLabel('Służbowy e-mail').fill(email)
const reps = await admin.locator('#acc-rep option:not([disabled])').allTextContents()
if (!reps.length) throw new Error('no free representative left: delete an E2E account first')
await admin.getByLabel('Handlowiec w systemie danych').selectOption(reps[0])
await admin.getByRole('button', { name: 'Utwórz konto' }).click()
await admin.getByText('Konto Test E2E utworzone').waitFor()
const other = await (await browser.newContext()).newPage()
await login(other, '/logowanie', email, await passwordFromMailpit(email))
await other.waitForURL('**/moje-raporty')
for (const path of [`/api/v1/files/${fileId}`, `/api/v1/files/${fileId}/content`]) {
  const r = await other.request.get(`${BASE}${path}`)
  if (r.status() !== 404) throw new Error(`other representative got ${r.status()} on ${path}`)
}
await other.goto(`${BASE}/moje-raporty/${fileId}`)
await other.getByText('Ten raport jest niedostępny').waitFor()
steps.push('another representative: 404 on metadata and content, „niedostępny” in the UI')

admin.once('dialog', (d) => d.accept())
await admin.locator('li', { hasText: email }).getByRole('button', { name: /Usuń/ }).click()
await admin.locator('li', { hasText: email }).waitFor({ state: 'detached' })
steps.push('cleanup: E2E account deleted')

await browser.close()
const unexpected = consoleErrors.filter((e) => !/404|Not Found/.test(e))
if (unexpected.length) throw new Error('console errors:\n' + unexpected.join('\n'))
console.log('CP-001 (manual) and CP-003 (files) passed:\n- ' + steps.join('\n- '))
