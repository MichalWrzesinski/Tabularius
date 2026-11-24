<script setup lang="ts">
// Mockup: docs/design/mockups/desktop/00-strona-glowna.html (+ mobile).
import logo from '../assets/logo.svg'
import Ico from '../components/Ico.vue'
import LegalLinks from '../components/LegalLinks.vue'
</script>

<template>
  <div class="home">
    <header class="home-header">
      <RouterLink class="brand" to="/" aria-label="Tabularius — strona główna"><img :src="logo" alt="" />Tabularius</RouterLink>
      <span>OD WIADOMOŚCI DO RAPORTU</span>
    </header>
    <main id="main">
      <div class="portals">
        <section class="portal hero blue" aria-labelledby="employee-title">
          <div class="portal-icon"><Ico name="mail" :width="1.8" /></div>
          <div class="eyebrow">Panel pracownika</div>
          <h1 id="employee-title">Twój raport<br />Na wyciągnięcie ręki</h1>
          <p class="intro">Wyślij prośbę e-mailem i odbierz gotowy raport. Zaloguj się, aby przejść do swoich raportów.</p>
          <RouterLink class="cta blue" to="/logowanie">Logowanie pracownika <Ico name="arrow" :width="2" /></RouterLink>
        </section>
        <section class="portal hero" aria-labelledby="admin-title">
          <div class="portal-icon"><Ico name="grid" :width="1.8" /></div>
          <div class="eyebrow">Panel administratora</div>
          <h2 id="admin-title">Wszystkie zgłoszenia<br />W jednym miejscu</h2>
          <p class="intro">Przeglądaj wiadomości pracowników, sprawdzaj status realizacji i pobieraj gotowe raporty.</p>
          <RouterLink class="cta" to="/admin/logowanie">Logowanie administratora <Ico name="arrow" :width="2" /></RouterLink>
        </section>
      </div>
      <section class="about" aria-labelledby="about-title">
        <div>
          <div class="eyebrow">Poznaj Tabularius</div>
          <h2 id="about-title">Od zwykłej wiadomości<br />do potrzebnych danych.</h2>
          <p>
            Tabularius łączy zgłoszenia e-mail z przygotowaniem raportów. Pracownicy otrzymują potrzebne dane, a administratorzy mają
            przejrzysty widok realizacji zgłoszeń.
          </p>
        </div>
        <ol class="steps">
          <li>
            <span class="step-number">01</span>
            <div>
              <h3>Opisz, czego potrzebujesz</h3>
              <p>Wyślij e-mail z prośbą o przygotowanie raportu.</p>
            </div>
          </li>
          <li>
            <span class="step-number">02</span>
            <div>
              <h3>Zgłoszenie trafia do realizacji</h3>
              <p>Administrator sprawdza jego status w panelu.</p>
            </div>
          </li>
          <li>
            <span class="step-number">03</span>
            <div>
              <h3>Odbierz gotowy raport</h3>
              <p>Gdy będzie gotowy, otrzymasz link do pobrania.</p>
            </div>
          </li>
        </ol>
      </section>
    </main>
    <footer class="home-footer">
      <span>© 2026 Tabularius. Wszelkie prawa zastrzeżone.</span>
      <LegalLinks />
    </footer>
  </div>
</template>
