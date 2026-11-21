<script setup lang="ts">
import { ArrowRight, ShieldCheck, Users } from 'lucide-vue-next'
import BrandMark from '../components/BrandMark.vue'
import { DEMO_MAILBOX } from '../mock/data'
</script>

<template>
  <div class="home">
    <header class="home__head"><BrandMark :size="48" /></header>
    <main id="main" class="home__main">
      <p class="eyebrow">Od wiadomości do raportu</p>
      <h1>Raporty sprzedażowe na prośbę wysłaną zwykłym e-mailem</h1>
      <p class="lead">
        Handlowiec pisze, czego potrzebuje. Tabularius rozpoznaje prośbę, sprawdza uprawnienia i przygotowuje plik CSV
        dostępny po zalogowaniu. Demo działa na fikcyjnych danych hurtowni części samochodowych.
      </p>
      <div class="panels">
        <section class="panel panel--emp">
          <span class="sym"><Users aria-hidden="true" /></span>
          <h2>Dla pracownika</h2>
          <p>Wyślij prośbę na <b>{{ DEMO_MAILBOX }}</b> i odbierz raport w „Moich raportach”.</p>
          <RouterLink class="btn" to="/logowanie">Panel pracownika<ArrowRight aria-hidden="true" /></RouterLink>
        </section>
        <section class="panel panel--admin">
          <span class="sym"><ShieldCheck aria-hidden="true" /></span>
          <h2>Dla administratora</h2>
          <p>Śledź zgłoszenia, statusy, pliki i dziennik zdarzeń.</p>
          <RouterLink class="btn" to="/admin/logowanie">Panel administratora<ArrowRight aria-hidden="true" /></RouterLink>
        </section>
      </div>
      <p class="note">Wersja demonstracyjna: widoki są wspólne dla uczestników. Nie wysyłaj danych poufnych.</p>
    </main>
    <footer class="home__foot">© 2026 Tabularius</footer>
  </div>
</template>

<style scoped>
.home {
  min-height: 100vh;
  background: #fff;
  display: flex;
  flex-direction: column;
}
.home__head,
.home__main,
.home__foot {
  width: 100%;
  max-width: 1080px;
  margin: 0 auto;
  padding: 0 20px;
}
.home__head {
  padding-top: 32px;
}
.home__main {
  flex: 1;
  padding-top: 48px;
}
.eyebrow {
  font-size: 12px;
  letter-spacing: 2px;
  text-transform: uppercase;
  font-weight: 700;
  color: var(--admin-600);
}
h1 {
  font-size: clamp(30px, 4.4vw, 48px);
  letter-spacing: -1.2px;
  line-height: 1.12;
  margin: 14px 0 18px;
  max-width: 820px;
}
.lead {
  font-size: 18px;
  color: var(--ink-soft);
  max-width: 700px;
}
.panels {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
  gap: 20px;
  margin: 40px 0 24px;
}
.panel {
  border-radius: 20px;
  padding: 28px;
  color: #fff;
  display: flex;
  flex-direction: column;
  gap: 14px;
  align-items: flex-start;
}
.panel p {
  overflow-wrap: anywhere;
}
.panel--emp {
  --accent: var(--emp-600);
  --accent-light: #fff;
  --accent-dark: #d6ecec;
  background: linear-gradient(145deg, var(--emp-hero-1), var(--emp-hero-2) 65%, var(--emp-hero-3));
}
.panel--admin {
  --accent: var(--admin-600);
  --accent-light: #fff;
  --accent-dark: #e3d8f2;
  background: linear-gradient(145deg, var(--admin-side-1), var(--admin-side-2) 65%, var(--admin-side-3));
}
.panel .btn {
  color: var(--accent);
  background: #fff;
}
.panel h2 {
  font-size: 22px;
}
.note {
  font-size: 13px;
  color: var(--muted);
}
.home__foot {
  text-align: right;
  font-size: 12px;
  color: var(--muted);
  padding-bottom: 24px;
}
</style>
