<script setup lang="ts">
// Mockup shell: docs/design/mockups/desktop/03-przeglad.html (sidebar, topbar) and mobile/03-przeglad.html
// (mobileheader with details menu, mobilecontext). The employee variant reuses it with the teal palette.
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import logo from '../assets/logo.svg'
import { listTickets } from '../lib/tickets'
import { proto } from '../mock/api'
import { logout, session } from '../lib/session'
import { initials, openTicketNumber } from '../lib/ticket'
import Ico from './Ico.vue'

const props = defineProps<{ role: 'admin' | 'employee' }>()
const route = useRoute()
const router = useRouter()
const isAdmin = computed(() => props.role === 'admin')

const openCount = ref<number | null>(null)
if (props.role === 'admin') {
  listTickets()
    .then((t) => {
      openCount.value = t.filter((x) => !['completed', 'rejected'].includes(x.status)).length
    })
    .catch(() => (openCount.value = null))
}

const nav = computed(() =>
  isAdmin.value
    ? [
        { to: '/admin', label: 'Przegląd', icon: 'home', exact: true },
        { to: '/admin/zgloszenia', label: 'Zgłoszenia', icon: 'mail', count: openCount.value },
        { to: '/admin/pliki', label: 'Pliki', icon: 'folder' },
        { to: '/admin/dane', label: 'Dostępne dane', icon: 'db' },
        { to: '/admin/pracownicy', label: 'Pracownicy', icon: 'users' },
        { to: '/admin/dziennik', label: 'Dziennik zdarzeń', icon: 'calendar' },
      ]
    : [{ to: '/moje-raporty', label: 'Moje raporty', icon: 'folder', exact: false }],
)
const settingsTo = computed(() => (isAdmin.value ? '/admin/ustawienia' : '/moje-raporty/konto'))
const workspace = computed(() => (isAdmin.value ? 'PANEL ADMINISTRATORA' : 'PANEL PRACOWNIKA'))
const who = computed(() => {
  const name = session.me?.name ?? (isAdmin.value ? 'Administrator' : 'Pracownik')
  return { name, initials: initials(name) }
})

function active(item: { to: string; exact?: boolean }) {
  if (item.exact) return route.path === item.to
  return route.path.startsWith(item.to) && !route.path.startsWith(settingsTo.value)
}
const crumb = computed(() => {
  if (route.params.id) return `Zgłoszenia / ${openTicketNumber.value ? '#' + openTicketNumber.value : 'Szczegóły'}`
  if (route.path === '/admin/zgloszenia/nowe') return 'Zgłoszenia / Nowe'
  return (route.meta.title as string) ?? ''
})

const drawer = ref<HTMLDetailsElement | null>(null)
const profileOpen = ref(false)
watch(
  () => route.fullPath,
  () => {
    if (drawer.value) drawer.value.open = false
    profileOpen.value = false
  },
)
function onKey(e: KeyboardEvent) {
  if (e.key !== 'Escape') return
  if (drawer.value) drawer.value.open = false
  profileOpen.value = false
}
async function signOut() {
  await logout().catch(() => undefined)
  void router.push(isAdmin.value ? '/admin/logowanie' : '/logowanie')
}
</script>

<template>
  <div class="screen app" :class="{ 'x-emp': !isAdmin }" @keydown="onKey">
    <header class="mobileheader">
      <RouterLink class="brand" :to="isAdmin ? '/admin' : '/moje-raporty'" style="color: inherit"
        ><img :src="logo" alt="" />Tabularius</RouterLink
      >
      <details ref="drawer" class="menudrawer">
        <summary aria-label="Otwórz menu"><Ico name="menu" :width="2" /></summary>
        <nav class="drawer" aria-label="Nawigacja">
          <div class="workspace">{{ workspace }}</div>
          <RouterLink
            v-for="item in nav"
            :key="item.to"
            class="nav"
            :class="{ active: active(item) }"
            :to="item.to"
            :aria-current="active(item) ? 'page' : undefined"
          >
            <Ico :name="item.icon" />{{ item.label }}<small v-if="'count' in item && item.count">{{ item.count }}</small>
          </RouterLink>
          <RouterLink class="nav" :to="settingsTo"><Ico name="settings" />Ustawienia</RouterLink>
          <button class="nav" type="button" @click="signOut"><Ico name="logout" />Wyloguj</button>
        </nav>
      </details>
    </header>

    <aside class="sidebar" aria-label="Nawigacja">
      <RouterLink class="brand" :to="isAdmin ? '/admin' : '/moje-raporty'" style="color: inherit"
        ><img :src="logo" alt="" />Tabularius</RouterLink
      >
      <div class="workspace">{{ workspace }}</div>
      <nav>
        <RouterLink
          v-for="item in nav"
          :key="item.to"
          class="nav"
          :class="{ active: active(item) }"
          :to="item.to"
          :aria-current="active(item) ? 'page' : undefined"
        >
          <Ico :name="item.icon" />{{ item.label }}<small v-if="'count' in item && item.count">{{ item.count }}</small>
        </RouterLink>
      </nav>
      <div class="sidebottom">
        <RouterLink class="nav" :class="{ active: route.path === settingsTo }" :to="settingsTo"
          ><Ico name="settings" />Ustawienia</RouterLink
        >
        <p>{{ proto.mode === 'demo' ? 'Środowisko demonstracyjne' : 'Środowisko organizacji' }} <span style="color: #c3a8f1">●</span></p>
      </div>
    </aside>

    <main class="main">
      <div class="mobilecontext">
        <span>{{ isAdmin ? 'Panel administratora' : 'Panel pracownika' }}</span
        ><span class="account" :title="who.name">{{ who.initials }}</span>
      </div>

      <div class="topbar">
        <div>
          {{ isAdmin ? 'Panel administratora' : 'Panel pracownika' }} &nbsp; / &nbsp; <span style="color: #604c72">{{ crumb }}</span>
        </div>
        <div class="x-profile">
          <button
            class="profile"
            type="button"
            :aria-expanded="profileOpen"
            aria-controls="profile-menu"
            @click="profileOpen = !profileOpen"
          >
            <div class="avatar" aria-hidden="true">{{ who.initials }}</div>
            <div>
              <b>{{ who.name }}</b
              ><small>{{ proto.mode === 'demo' ? 'Konto demonstracyjne' : 'Organizacja demo' }}</small>
            </div>
            <Ico name="chevron" />
          </button>
          <div v-if="profileOpen" id="profile-menu" class="x-menu">
            <button type="button" @click="signOut"><Ico name="logout" />Wyloguj</button>
          </div>
        </div>
      </div>

      <div id="main" tabindex="-1" style="outline: none">
        <RouterView />
      </div>
      <footer class="appfooter">© 2026 Tabularius</footer>
    </main>
  </div>
</template>
