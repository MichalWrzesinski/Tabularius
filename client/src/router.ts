import { createRouter, createWebHistory } from 'vue-router'
import { proto } from './mock/api'

export const router = createRouter({
  history: createWebHistory(),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    { path: '/', component: () => import('./views/HomeView.vue'), meta: { title: 'Tabularius' } },
    { path: '/logowanie', component: () => import('./views/LoginView.vue'), props: { role: 'employee' }, meta: { title: 'Logowanie pracownika' } },
    { path: '/admin/logowanie', component: () => import('./views/LoginView.vue'), props: { role: 'admin' }, meta: { title: 'Logowanie administratora' } },
    {
      path: '/moje-raporty',
      component: () => import('./components/AppShell.vue'),
      props: { role: 'employee' },
      meta: { role: 'employee' },
      children: [
        { path: '', component: () => import('./views/employee/MyReportsView.vue'), meta: { title: 'Moje raporty' } },
        { path: 'konto', component: () => import('./views/SettingsView.vue'), meta: { title: 'Ustawienia' } },
        { path: ':fileId', component: () => import('./views/employee/ReportView.vue'), props: true, meta: { title: 'Raport' } },
        { path: 'potwierdz/:ticketId', component: () => import('./views/employee/ConfirmView.vue'), props: true, meta: { title: 'Potwierdź prośbę' } },
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
        { path: 'zgloszenia/:id', component: () => import('./views/admin/TicketView.vue'), props: true, meta: { title: 'Zgłoszenie' } },
        { path: 'pliki', component: () => import('./views/admin/FilesView.vue'), meta: { title: 'Pliki' } },
        { path: 'dane', component: () => import('./views/admin/DataView.vue'), meta: { title: 'Dostępne dane' } },
        { path: 'pracownicy', component: () => import('./views/admin/AccountsView.vue'), meta: { title: 'Pracownicy' } },
        { path: 'dziennik', component: () => import('./views/admin/AuditView.vue'), meta: { title: 'Dziennik zdarzeń' } },
        { path: 'ustawienia', component: () => import('./views/SettingsView.vue'), meta: { title: 'Ustawienia' } },
      ],
    },
    { path: '/:pathMatch(.*)*', component: () => import('./views/NotFoundView.vue'), meta: { title: 'Nie znaleziono' } },
  ],
})

router.beforeEach((to) => {
  const role = to.matched.find((r) => r.meta.role)?.meta.role
  if (!role) return true
  const s = proto.session
  if (s?.role === role) return true
  // The employee link from an e-mail opens the report after login (no automatic download).
  return { path: role === 'admin' ? '/admin/logowanie' : '/logowanie', query: { next: to.fullPath } }
})

router.afterEach((to) => {
  const t = to.meta.title as string | undefined
  document.title = t && t !== 'Tabularius' ? `${t} — Tabularius` : 'Tabularius — Od wiadomości do raportu'
})
