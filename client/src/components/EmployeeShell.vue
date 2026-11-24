<script setup lang="ts">
// Employee shell: one teal header with all links (no sidebar — the employee has a single section).
// Colours come from the employee login hero (mockup 01-logowanie-pracownik); content uses the mockup classes.
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import logo from '../assets/logo.svg'
import { logout, session } from '../lib/session'
import Ico from './Ico.vue'
import LegalLinks from './LegalLinks.vue'

const route = useRoute()
const router = useRouter()
const drawer = ref<HTMLDetailsElement | null>(null)
const links = [
  {
    to: '/moje-raporty',
    label: 'Moje raporty',
    icon: 'folder',
    match: (p: string) => p.startsWith('/moje-raporty') && p !== '/moje-raporty/konto',
  },
  { to: '/moje-raporty/konto', label: 'Ustawienia', icon: 'settings', match: (p: string) => p === '/moje-raporty/konto' },
]
watch(
  () => route.fullPath,
  () => {
    if (drawer.value) drawer.value.open = false
  },
)
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape' && drawer.value) drawer.value.open = false
}
async function signOut() {
  await logout().catch(() => undefined)
  void router.push('/logowanie')
}
</script>

<template>
  <div class="screen x-emp x-empshell" @keydown="onKey">
    <header class="x-emphead">
      <div class="x-empinner">
        <RouterLink class="brand" to="/moje-raporty"><img :src="logo" alt="" />Tabularius</RouterLink>
        <nav class="x-emplinks" aria-label="Nawigacja">
          <RouterLink
            v-for="l in links"
            :key="l.to"
            :to="l.to"
            class="x-emplink"
            :class="{ active: l.match(route.path) }"
            :aria-current="l.match(route.path) ? 'page' : undefined"
          >
            <Ico :name="l.icon" />{{ l.label }}
          </RouterLink>
        </nav>
        <div class="x-empuser">
          <span class="x-empname"
            ><b>{{ session.me?.name ?? 'Pracownik' }}</b
            ><small>{{ session.me?.shared ? 'Konto demonstracyjne' : 'Panel pracownika' }}</small></span
          >
          <button class="x-emplink" type="button" @click="signOut"><Ico name="logout" />Wyloguj</button>
        </div>
        <details ref="drawer" class="menudrawer x-empmenu">
          <summary aria-label="Otwórz menu"><Ico name="menu" :width="2" /></summary>
          <nav class="drawer" aria-label="Nawigacja">
            <div class="workspace">PANEL PRACOWNIKA</div>
            <RouterLink
              v-for="l in links"
              :key="l.to"
              :to="l.to"
              class="nav"
              :class="{ active: l.match(route.path) }"
              :aria-current="l.match(route.path) ? 'page' : undefined"
            >
              <Ico :name="l.icon" />{{ l.label }}
            </RouterLink>
            <button class="nav" type="button" @click="signOut"><Ico name="logout" />Wyloguj</button>
          </nav>
        </details>
      </div>
    </header>
    <main id="main" class="main x-empmain" tabindex="-1">
      <RouterView />
      <footer class="x-empfoot">© 2026 Tabularius <LegalLinks /></footer>
    </main>
  </div>
</template>
