<script setup lang="ts">
import { LogOut } from 'lucide-vue-next'
import { useRouter } from 'vue-router'
import { logout, proto } from '../mock/api'
import BrandMark from './BrandMark.vue'

const router = useRouter()
function signOut() {
  logout()
  void router.push('/logowanie')
}
</script>

<template>
  <div class="emp">
    <header class="emp__bar">
      <div class="emp__inner">
        <RouterLink to="/moje-raporty" class="emp__brand"><BrandMark :size="40" /></RouterLink>
        <div class="emp__user">
          <span class="emp__name">{{ proto.session?.name }}</span>
          <button class="emp__logout" type="button" aria-label="Wyloguj" @click="signOut"><LogOut aria-hidden="true" /><span>Wyloguj</span></button>
        </div>
      </div>
    </header>
    <main id="main" class="emp__main" tabindex="-1">
      <RouterView />
    </main>
    <footer class="emp__footer">© 2026 Tabularius</footer>
  </div>
</template>

<style scoped>
.emp {
  --accent: var(--emp-600);
  --accent-light: var(--emp-500);
  --accent-dark: var(--emp-700);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}
.emp__bar {
  color: #fff;
  background:
    url('../assets/pattern-flow.svg') right center / 700px auto no-repeat,
    radial-gradient(ellipse at 100% 38%, #118b91 0, transparent 68%),
    linear-gradient(145deg, var(--emp-hero-1), var(--emp-hero-2) 65%, var(--emp-hero-3));
}
.emp__inner,
.emp__main,
.emp__footer {
  width: 100%;
  max-width: 1080px;
  margin: 0 auto;
  padding-left: 20px;
  padding-right: 20px;
}
.emp__inner {
  min-height: 76px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.emp__brand {
  color: #fff;
  text-decoration: none;
}
.emp__user {
  display: flex;
  align-items: center;
  gap: 14px;
  font-size: 14px;
}
.emp__logout {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 0 14px;
  border: 1px solid #ffffff40;
  border-radius: 10px;
  background: #ffffff14;
  color: #fff;
  cursor: pointer;
}
.emp__logout svg {
  width: 18px;
}
.emp__main {
  flex: 1;
  padding-bottom: 40px;
  outline: none;
}
.emp__footer {
  text-align: right;
  font-size: 12px;
  color: var(--muted);
  padding-bottom: 24px;
}
@media (max-width: 640px) {
  .emp__name,
  .emp__logout span {
    display: none;
  }
  .emp__logout {
    width: 48px;
    padding: 0;
    justify-content: center;
  }
}
</style>
