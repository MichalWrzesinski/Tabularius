// CP-004 (docs/qa/critical-paths.md) through the UI against the running stack (make up):
// admin creates a representative → password e-mail in Mailpit → representative logs in → admin blocks → access ends.
// Run (from client/): see client/README.md.
import { chromium } from 'playwright'

const BASE = process.env.BASE_URL ?? 'http://host.docker.internal:8080'
const MAILPIT = process.env.MAILPIT_URL ?? 'http://host.docker.internal:8025'
const email = `e2e.${Date.now()}@example.com`
const steps = []

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

const browser = await chromium.launch()
const admin = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage()
await admin.goto(`${BASE}/admin/logowanie`)
await admin.getByLabel('E-mail').fill('admin@example.com')
await admin.getByLabel('Hasło', { exact: true }).fill('Demo123!') // gitleaks:allow (public demo credential)
await admin.getByRole('button', { name: 'Zaloguj się' }).click()
await admin.waitForURL('**/admin')
await admin.goto(`${BASE}/admin/pracownicy`)
await admin.getByRole('button', { name: 'Dodaj pracownika' }).click()
await admin.getByLabel('Imię i nazwisko').fill('Test E2E')
await admin.getByLabel('Służbowy e-mail').fill(email)
const reps = await admin.locator('#acc-rep option:not([disabled])').allTextContents()
if (!reps.length) throw new Error('no free representative left: delete an E2E account first')
await admin.getByLabel('Handlowiec w systemie danych').selectOption(reps[0])
await admin.getByRole('button', { name: 'Utwórz konto' }).click()
await admin.getByText('Konto Test E2E utworzone').waitFor()
steps.push('admin created account')

const password = await passwordFromMailpit(email)
steps.push('password e-mail captured (' + password.length + ' chars)')

const repCtx = await browser.newContext({ viewport: { width: 390, height: 844 } })
const rep = await repCtx.newPage()
await rep.goto(`${BASE}/logowanie`)
await rep.getByLabel('E-mail').fill(email)
await rep.getByLabel('Hasło', { exact: true }).fill(password)
await rep.getByRole('button', { name: 'Zaloguj się' }).click()
await rep.waitForURL('**/moje-raporty')
steps.push('representative logged in')

const repAdmin = await rep.request.get(`${BASE}/api/v1/admin/accounts`)
if (repAdmin.status() !== 403) throw new Error('representative reached the admin API: ' + repAdmin.status())
steps.push('representative refused on admin API (403)')

const row = admin.locator('li', { hasText: email })
await row.getByRole('button', { name: /Zablokuj/ }).click()
await row.getByText('Zablokowane').waitFor()
steps.push('admin blocked account')

const me = await rep.request.get(`${BASE}/api/v1/auth/me`)
if (me.status() !== 401) throw new Error('blocked session still valid: ' + me.status())
await rep.goto(`${BASE}/moje-raporty`)
await rep.waitForURL(/\/logowanie\?next=/)
steps.push('blocked representative sent to login (session revoked)')

admin.once('dialog', (d) => d.accept())
await row.getByRole('button', { name: /Usuń/ }).click()
await admin.locator('li', { hasText: email }).waitFor({ state: 'detached' })
steps.push('cleanup: account deleted')

await browser.close()
console.log('CP-004 passed:\n- ' + steps.join('\n- '))
