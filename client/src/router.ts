import { createRouter, createWebHistory } from 'vue-router'
import { guard, loadSession } from './lib/session'

export const router = createRouter({
  history: createWebHistory(),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    { path: '/', component: () => import('./views/HomeView.vue'), meta: { title: 'Tabularius' } },
    {
      path: '/logowanie',
      component: () => import('./views/LoginView.vue'),
      props: { role: 'employee' },
      meta: { title: 'Logowanie pracownika' },
    },
    {
      path: '/admin/logowanie',
      component: () => import('./views/LoginView.vue'),
      props: { role: 'admin' },
      meta: { title: 'Logowanie administratora' },
    },
    {
      path: '/moje-raporty',
      component: () => import('./components/EmployeeShell.vue'),
      meta: { role: 'employee' },
      children: [
        { path: '', component: () => import('./views/employee/MyReportsView.vue'), meta: { title: 'Moje raporty' } },
        { path: 'konto', component: () => import('./views/SettingsView.vue'), meta: { title: 'Ustawienia' } },
        { path: ':fileId', component: () => import('./views/employee/ReportView.vue'), props: true, meta: { title: 'Raport' } },
        {
          path: 'potwierdz/:ticketId',
          component: () => import('./views/employee/ConfirmView.vue'),
          props: true,
          meta: { title: 'Potwierdź prośbę' },
        },
      ],
    },
    {
      path: '/admin',
      component: () => import('./components/AppShell.vue'),
      props: { role: 'admin' },
      meta: { role: 'admin' },
      children: [
        { path: '', component: () => import('./views/admin/OverviewView.vue'), meta: { title: 'Przegląd' } },
        { path: 'zgloszenia', component: () => import('./views/admin/TicketsView.vue'), meta: { title: 'Zgłoszenia' } },
        { path: 'zgloszenia/nowe', component: () => import('./views/admin/NewTicketView.vue'), meta: { title: 'Nowe zgłoszenie' } },
        {
          path: 'zgloszenia/:ticketId/parametry',
          component: () => import('./views/admin/NewTicketView.vue'),
          props: true,
          meta: { title: 'Ustaw parametry' },
        },
        { path: 'zgloszenia/:id', component: () => import('./views/admin/TicketView.vue'), props: true, meta: { title: 'Zgłoszenie' } },
        { path: 'pliki', component: () => import('./views/admin/FilesView.vue'), meta: { title: 'Pliki' } },
        { path: 'dane', component: () => import('./views/admin/DataView.vue'), meta: { title: 'Dostępne dane' } },
        { path: 'pracownicy', component: () => import('./views/admin/AccountsView.vue'), meta: { title: 'Pracownicy' } },
        { path: 'dziennik', component: () => import('./views/admin/AuditView.vue'), meta: { title: 'Dziennik zdarzeń' } },
        { path: 'ustawienia', component: () => import('./views/SettingsView.vue'), meta: { title: 'Ustawienia' } },
      ],
    },
    {
      path: '/polityka-prywatnosci',
      component: () => import('./views/LegalView.vue'),
      props: { doc: 'privacy' },
      meta: { title: 'Polityka prywatności' },
    },
    { path: '/regulamin', component: () => import('./views/LegalView.vue'), props: { doc: 'terms' }, meta: { title: 'Regulamin' } },
    { path: '/:pathMatch(.*)*', component: () => import('./views/NotFoundView.vue'), meta: { title: 'Nie znaleziono' } },
  ],
})

router.beforeEach(async (to) => {
  const area = to.matched.find((r) => r.meta.role)?.meta.role as 'admin' | 'employee' | undefined
  if (!area) return true
  let me = null
  try {
    me = await loadSession()
  } catch {
    me = null // API unreachable: treat as logged out; the login screen shows the error on submit
  }
  if (guard(area === 'admin' ? 'admin' : area === 'employee' ? 'rep' : undefined, me) === 'allow') return true
  // The employee link from an e-mail opens the report after login (no automatic download).
  return { path: area === 'admin' ? '/admin/logowanie' : '/logowanie', query: { next: to.fullPath } }
})

router.afterEach((to) => {
  const t = to.meta.title as string | undefined
  document.title = t && t !== 'Tabularius' ? `${t} — Tabularius` : 'Tabularius — Od wiadomości do raportu'
})
