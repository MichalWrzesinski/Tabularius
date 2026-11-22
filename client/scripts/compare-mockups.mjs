import { chromium } from 'playwright'
const BASE = process.env.BASE_URL ?? 'http://host.docker.internal:8080'
const pairs = [
  ['00-strona-glowna', '/', null],
  ['01-logowanie-pracownik', '/logowanie', null],
  ['02-logowanie-admin', '/admin/logowanie', null],
  ['03-przeglad', '/admin', 'admin'],
  // The newest completed ticket on the running stack (make seed queues sample tickets).
  ['04-zgloszenie', 'latest-completed-ticket', 'admin'],
]
const sizes = [{ n: 'desktop', w: 1500, h: 1000 }, { n: 'mobile', w: 390, h: 844 }]
const b = await chromium.launch()
for (const s of sizes) {
  const ctx = await b.newContext({ viewport: { width: s.w, height: s.h }, locale: 'pl-PL', timezoneId: 'Europe/Warsaw' })
  const page = await ctx.newPage()
  for (const [file, url, role] of pairs) {
    await page.goto(`file:///mock/${s.n}/${file}.html`)
    await page.waitForTimeout(300)
    await page.screenshot({ path: `/out/${s.n}-${file}-A-mockup.png`, fullPage: s.n === 'mobile' })
    if (role) {
      const res = await ctx.request.post(BASE + '/api/v1/auth/login', { headers: { 'X-Tabularius-Request': '1' }, data: { email: 'admin@example.com', password: 'Demo123!' } }) // gitleaks:allow (public demo credential)
      if (!res.ok()) throw new Error('demo admin login failed: ' + res.status())
    }
    let target = url
    if (url === 'latest-completed-ticket') {
      const list = await (await ctx.request.get(BASE + '/api/v1/admin/tickets')).json()
      const done = list.items.find((t) => t.status === 'completed')
      if (!done) throw new Error('no completed ticket: run make seed and let the worker finish')
      target = `/admin/zgloszenia/${done.id}`
    }
    await page.goto(BASE + target)
    await page.waitForTimeout(1200)
    await page.screenshot({ path: `/out/${s.n}-${file}-B-app.png`, fullPage: s.n === 'mobile' })
    const o = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    if (o > 0) console.log('overflow', s.n, file, o)
  }
  await ctx.close()
}
await b.close()
console.log('done')
