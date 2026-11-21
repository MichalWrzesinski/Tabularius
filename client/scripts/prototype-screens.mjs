// PROTOTYPE check: screenshots at 1440 and 360 px, horizontal-overflow and console-error checks,
// and the clarification and confirmation walkthroughs. How to run: client/README.md.
import { chromium } from 'playwright'
const BASE = 'http://host.docker.internal:5173'
const OUT = '/out'
const sizes = [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 360, height: 780 }]
const results = []

async function check(page, label, size) {
  await page.waitForTimeout(900)
  const o = await page.evaluate(() => {
    const w = document.documentElement.clientWidth
    const wide = [...document.querySelectorAll('body *')].filter((e) => {
      const r = e.getBoundingClientRect()
      return r.right > w + 1 && getComputedStyle(e).position !== 'fixed' && !e.closest('.preview-table')
    }).slice(0, 5).map((e) => e.tagName + '.' + [...e.classList].join('.'))
    return { scroll: document.documentElement.scrollWidth, w, wide }
  })
  results.push({ label, size: size.name, overflow: o.scroll > o.w, wide: o.wide })
  await page.screenshot({ path: `${OUT}/${size.name}-${label}.png`, fullPage: true })
}

async function login(page, role) {
  await page.goto(`${BASE}${role === 'admin' ? '/admin/logowanie' : '/logowanie'}`)
  await page.getByRole('button', { name: 'Wypełnij dane demo' }).click()
  await page.getByRole('button', { name: 'Zaloguj się' }).click()
  await page.waitForURL(role === 'admin' ? '**/admin' : '**/moje-raporty')
}

const browser = await chromium.launch()
for (const size of sizes) {
  const ctx = await browser.newContext({ viewport: { width: size.width, height: size.height }, locale: 'pl-PL', timezoneId: 'Europe/Warsaw' })
  const page = await ctx.newPage()
  const errors = []
  page.on('pageerror', (e) => errors.push(e.message))
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()))

  await page.goto(`${BASE}/`); await check(page, '00-home', size)
  await page.goto(`${BASE}/logowanie`); await check(page, '01-login-employee', size)
  await page.goto(`${BASE}/admin/logowanie`); await check(page, '02-login-admin', size)
  await login(page, 'admin')
  await check(page, '03-overview', size)
  for (const [path, label] of [
    ['/admin/zgloszenia/T-1042', '04-ticket-completed'],
    ['/admin/zgloszenia/T-1037', '05-ticket-out-of-catalog'],
    ['/admin/zgloszenia/T-1034', '06-ticket-out-of-scope'],
    ['/admin/zgloszenia/T-1039', '07-ticket-provider-error'],
    ['/admin/zgloszenia/T-1035', '08-ticket-ambiguous-customer'],
    ['/admin/zgloszenia/T-1040', '09-ticket-clarification'],
    ['/admin/pliki', '11-files'], ['/admin/pracownicy', '12-accounts'], ['/admin/dziennik', '13-audit'], ['/admin/dane', '14-data'],
    ['/admin/zgloszenia', '15-tickets-all'],
  ]) { await page.goto(BASE + path); await check(page, label, size) }

  // CP-002 walkthrough (in-memory state: do it without reloading)
  await page.goto(`${BASE}/admin/zgloszenia/T-1040`)
  await page.getByRole('button', { name: 'Wyślij odpowiedź' }).click()
  await page.getByText('Ukończone', { exact: true }).first().waitFor({ timeout: 10000 })
  await check(page, '10-ticket-clarification-answered', size)
  await page.getByRole('tab', { name: /Historia/ }).click()
  await check(page, '10b-ticket-history', size)

  if (size.name === 'mobile') {
    await page.getByRole('button', { name: 'Otwórz menu' }).click()
    await check(page, '16-mobile-menu', size)
  }

  // Employee
  await page.evaluate(() => sessionStorage.clear())
  await page.goto(`${BASE}/moje-raporty/f-7c1e9a`)
  await page.waitForURL(/\/logowanie\?next=/)
  await check(page, '20-employee-login-from-link', size)
  await page.getByRole('button', { name: 'Wypełnij dane demo' }).click()
  await page.getByRole('button', { name: 'Zaloguj się' }).click()
  await page.waitForURL('**/moje-raporty/f-7c1e9a')
  await check(page, '21a-employee-report-loading', size)
  await page.getByRole('button', { name: 'Pobierz CSV' }).waitFor()
  await check(page, '21-employee-report', size)
  const dl = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Pobierz CSV' }).click()
  const d = await dl
  await d.saveAs(`${OUT}/sample-${size.name}.csv`)
  await page.goto(`${BASE}/moje-raporty`); await check(page, '22-my-reports', size)
  await page.goto(`${BASE}/moje-raporty/potwierdz/T-1036`); await check(page, '23-confirm', size)
  await page.getByRole('button', { name: 'Potwierdź i przygotuj raport' }).click()
  await page.getByRole('link', { name: 'Otwórz raport' }).waitFor({ timeout: 10000 })
  await check(page, '24-confirmed', size)
  await page.getByRole('link', { name: 'Otwórz raport' }).click()
  await page.getByRole('button', { name: 'Pobierz CSV' }).waitFor()
  await check(page, '25-discount-report', size)
  await page.goto(`${BASE}/moje-raporty/f-0000ff`); await check(page, '26-foreign-file', size)
  await page.goto(`${BASE}/admin`); await page.waitForURL(/\/admin\/logowanie\?next=/)
  results.push({ label: 'employee-blocked-from-admin', size: size.name, overflow: false, wide: [], ok: true })
  results.push({ label: 'errors', size: size.name, errors })
  await ctx.close()
}
await browser.close()
console.log(JSON.stringify(results.filter((r) => r.overflow || (r.errors && r.errors.length) || r.label === 'errors'), null, 1))
console.log('checked', results.length)
