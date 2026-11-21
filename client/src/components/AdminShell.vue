<script setup lang="ts">
import { CalendarClock, ChevronDown, Database, FolderClosed, House, LogOut, Mail, Menu, Users, X } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { logout, proto } from '../mock/api'
import BrandMark from './BrandMark.vue'

const route = useRoute()
const router = useRouter()
const menuOpen = ref(false)
const profileOpen = ref(false)

const nav = [
  { to: '/admin', label: 'Przegląd', icon: House, exact: true },
  { to: '/admin/zgloszenia', label: 'Zgłoszenia', icon: Mail },
  { to: '/admin/pliki', label: 'Pliki', icon: FolderClosed },
  { to: '/admin/dane', label: 'Dostępne dane', icon: Database },
  { to: '/admin/pracownicy', label: 'Pracownicy', icon: Users },
  { to: '/admin/dziennik', label: 'Dziennik zdarzeń', icon: CalendarClock },
]

function isActive(item: (typeof nav)[number]) {
  return item.exact ? route.path === item.to : route.path.startsWith(item.to)
}

const crumb = computed(() => (route.meta.title as string) ?? '')
watch(() => route.fullPath, () => {
  menuOpen.value = false
  profileOpen.value = false
})

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    menuOpen.value = false
    profileOpen.value = false
  }
}

function signOut() {
  logout()
  void router.push('/admin/logowanie')
}
</script>

<template>
  <div class="admin" @keydown="onKey">
    <aside class="sidebar" aria-label="Nawigacja panelu">
      <RouterLink to="/admin" class="sidebar__brand"><BrandMark /></RouterLink>
      <p class="workspace">Panel administratora</p>
      <nav>
        <RouterLink v-for="item in nav" :key="item.to" :to="item.to" class="nav" :class="{ 'is-active': isActive(item) }" :aria-current="isActive(item) ? 'page' : undefined">
          <component :is="item.icon" aria-hidden="true" />{{ item.label }}
        </RouterLink>
      </nav>
      <p class="sidebar__env">{{ proto.mode === 'demo' ? 'Środowisko demonstracyjne' : 'Środowisko organizacji' }}</p>
    </aside>

    <header class="mobilebar">
      <RouterLink to="/admin"><BrandMark :size="40" /></RouterLink>
      <button class="mobilebar__menu" type="button" :aria-expanded="menuOpen" aria-controls="admin-menu" @click="menuOpen = !menuOpen">
        <component :is="menuOpen ? X : Menu" aria-hidden="true" />
        <span class="sr-only">{{ menuOpen ? 'Zamknij menu' : 'Otwórz menu' }}</span>
      </button>
      <nav v-if="menuOpen" id="admin-menu" class="drawer" aria-label="Nawigacja panelu">
        <p class="workspace">Panel administratora</p>
        <RouterLink v-for="item in nav" :key="item.to" :to="item.to" class="nav" :class="{ 'is-active': isActive(item) }" :aria-current="isActive(item) ? 'page' : undefined">
          <component :is="item.icon" aria-hidden="true" />{{ item.label }}
        </RouterLink>
        <button class="nav" type="button" @click="signOut"><LogOut aria-hidden="true" />Wyloguj</button>
      </nav>
    </header>

    <div class="main">
      <div class="topbar">
        <p class="crumbs"><span>Panel administratora</span><span aria-hidden="true"> / </span><span class="crumbs__current">{{ crumb }}</span></p>
        <div class="profile">
          <button type="button" class="profile__btn" :aria-expanded="profileOpen" aria-controls="profile-menu" @click="profileOpen = !profileOpen">
            <span class="avatar" aria-hidden="true">AD</span>
            <span class="profile__text"><b>Administrator</b><small>{{ proto.mode === 'demo' ? 'Konto demonstracyjne' : 'Organizacja demo' }}</small></span>
            <ChevronDown aria-hidden="true" />
          </button>
          <div v-if="profileOpen" id="profile-menu" class="profile__menu">
            <button type="button" class="textlink" @click="signOut"><LogOut aria-hidden="true" />Wyloguj</button>
          </div>
        </div>
      </div>
      <main id="main" tabindex="-1">
        <RouterView />
      </main>
      <footer class="appfooter">© 2026 Tabularius</footer>
    </div>
  </div>
</template>

<style scoped>
.admin {
  --accent: var(--admin-600);
  --accent-light: var(--admin-500);
  --accent-dark: var(--admin-700);
  display: flex;
  min-height: 100vh;
}
.sidebar {
  position: sticky;
  top: 0;
  height: 100vh;
  width: 268px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  padding: 36px 22px 28px;
  color: #fff;
  background:
    url('../assets/pattern-flow.svg') right bottom / 390px 1000px no-repeat,
    radial-gradient(ellipse at 100% 40%, #5d2790 0, transparent 65%),
    linear-gradient(145deg, var(--admin-side-1), var(--admin-side-2) 65%, var(--admin-side-3));
  isolation: isolate;
}
.sidebar__brand {
  color: #fff;
  text-decoration: none;
  padding: 0 9px;
}
.workspace {
  font-size: 11px;
  letter-spacing: 2px;
  text-transform: uppercase;
  font-weight: 700;
  color: #c9b3da;
  margin: 44px 18px 14px;
}
.nav {
  display: flex;
  align-items: center;
  gap: 14px;
  width: 100%;
  min-height: 52px;
  padding: 0 18px;
  margin: 4px 0;
  border: 0;
  border-radius: 12px;
  background: transparent;
  color: #ece4f3;
  font-size: 15px;
  text-decoration: none;
  cursor: pointer;
}
.nav svg {
  width: 21px;
  height: 21px;
}
.nav:hover {
  background: #ffffff14;
}
.nav.is-active {
  background: linear-gradient(180deg, var(--admin-500), var(--admin-600));
  border-bottom: 3px solid var(--admin-700);
  font-weight: 700;
  color: #fff;
}
.nav:focus-visible {
  box-shadow: 0 0 0 3px var(--sun);
}
.sidebar__env {
  margin-top: auto;
  font-size: 12px;
  color: #d5c3e2;
  padding: 15px 18px 0;
  border-top: 1px solid #ffffff22;
}
.main {
  flex: 1;
  min-width: 0;
  padding: 0 clamp(20px, 3vw, 44px) 40px;
  display: flex;
  flex-direction: column;
}
main {
  flex: 1;
  outline: none;
}
.topbar {
  min-height: 88px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  border-bottom: 1px solid #eae5f0;
  font-size: 13px;
  color: var(--muted);
}
.crumbs__current {
  color: var(--ink-soft);
}
.profile {
  position: relative;
}
.profile__btn {
  display: flex;
  align-items: center;
  gap: 12px;
  border: 0;
  background: none;
  cursor: pointer;
  min-height: 48px;
  text-align: left;
}
.profile__btn svg {
  width: 18px;
}
.profile__text b {
  display: block;
  font-size: 13px;
}
.profile__text small {
  font-size: 11px;
  color: var(--muted);
}
.avatar {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  border-radius: 12px;
  background: #eee5fb;
  color: var(--admin-600);
  font-weight: 700;
  font-size: 12px;
}
.profile__menu {
  position: absolute;
  right: 0;
  top: 54px;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 4px 16px;
  box-shadow: 0 12px 30px #30183e1f;
  z-index: 10;
}
.appfooter {
  text-align: right;
  font-size: 12px;
  color: var(--muted);
  padding-top: 32px;
}
.mobilebar {
  display: none;
}
@media (max-width: 1023px) {
  .admin {
    display: block;
  }
  .sidebar {
    display: none;
  }
  .mobilebar {
    display: flex;
    position: relative;
    z-index: 20;
    min-height: 76px;
    padding: 16px 20px;
    align-items: center;
    justify-content: space-between;
    color: #fff;
    background: radial-gradient(ellipse at right, #5d2790, transparent 75%), #2c103b;
  }
  .mobilebar a {
    color: #fff;
    text-decoration: none;
  }
  .mobilebar__menu {
    display: grid;
    place-items: center;
    width: 48px;
    height: 48px;
    border: 0;
    border-radius: 10px;
    background: #ffffff1c;
    color: #fff;
    cursor: pointer;
  }
  .drawer {
    position: absolute;
    left: 0;
    right: 0;
    top: 100%;
    background: #32143f;
    padding: 8px 20px 20px;
    box-shadow: 0 15px 30px #2c103b40;
  }
  .drawer .workspace {
    margin: 12px 18px 10px;
  }
  .profile__text {
    display: none;
  }
  .topbar {
    min-height: 64px;
  }
}
</style>
