<script setup lang="ts">
import { FlaskConical, X } from 'lucide-vue-next'
import { ref } from 'vue'
import { proto } from '../mock/api'

const open = ref(false)

const scenarios = [
  { to: '/admin/zgloszenia/T-1042', text: 'Poprawne zlecenie → gotowy raport (CP-001)' },
  { to: '/admin/zgloszenia/T-1040', text: 'Brak okresu → pytanie → odpowiedź (CP-002)' },
  { to: '/admin/zgloszenia/T-1037', text: 'Raport płacowy / „usuń bazę” → odmowa' },
  { to: '/admin/zgloszenia/T-1034', text: 'Klient innego handlowca → odmowa' },
  { to: '/admin/zgloszenia/T-1039', text: 'Błąd dostawcy AI → interwencja (CP-005)' },
  { to: '/admin/zgloszenia/T-1035', text: 'Niejednoznaczny klient „Alfa” → wybór przez admina' },
  { to: '/moje-raporty/f-7c1e9a', text: 'Link z e-maila → logowanie → raport (pracownik)' },
  { to: '/moje-raporty/potwierdz/T-1036', text: 'Potwierdzenie prośby (tryb produkcyjny)' },
  { to: '/moje-raporty/f-0000ff', text: 'Link do cudzego / nieistniejącego pliku' },
]
</script>

<template>
  <div class="proto">
    <button class="proto__toggle" type="button" :aria-expanded="open" aria-controls="proto-panel" @click="open = !open">
      <FlaskConical aria-hidden="true" />Prototyp
    </button>
    <section v-if="open" id="proto-panel" class="proto__panel" aria-label="Ustawienia prototypu">
      <header>
        <h2>Prototyp</h2>
        <button class="icon-btn" type="button" aria-label="Zamknij ustawienia prototypu" @click="open = false"><X aria-hidden="true" /></button>
      </header>
      <p class="proto__note">Zaślepione API i dane syntetyczne. Nic nie jest wysyłane ani zapisywane poza tą kartą przeglądarki.</p>

      <fieldset>
        <legend>Środowisko</legend>
        <label><input v-model="proto.mode" type="radio" value="demo" /> Demo publiczne</label>
        <label><input v-model="proto.mode" type="radio" value="production" /> Produkcja (organizacja)</label>
      </fieldset>
      <fieldset>
        <legend>Stany</legend>
        <label><input v-model="proto.failLists" type="checkbox" /> Błąd pobierania list</label>
        <label><input v-model="proto.emptyEmployee" type="checkbox" /> Pracownik bez raportów</label>
        <label>
          Opóźnienie API
          <select v-model.number="proto.latencyMs" class="select">
            <option :value="150">krótkie (150 ms)</option>
            <option :value="450">typowe (450 ms)</option>
            <option :value="2500">długie (2,5 s)</option>
          </select>
        </label>
      </fieldset>
      <h3>Scenariusze</h3>
      <ul>
        <li v-for="s in scenarios" :key="s.to"><RouterLink :to="s.to" @click="open = false">{{ s.text }}</RouterLink></li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.proto {
  position: fixed;
  left: 14px;
  bottom: 14px;
  z-index: 50;
}
.proto__toggle {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 0 14px;
  border: 1px dashed #8a5f12;
  border-radius: 999px;
  background: #fff3d6;
  color: #6b4609;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 6px 18px #30183e22;
}
.proto__toggle svg {
  width: 18px;
}
@media (max-width: 760px) {
  .proto__toggle {
    min-height: 40px;
    padding: 0 12px;
    font-size: 13px;
    opacity: 0.92;
  }
}
.proto__panel {
  position: absolute;
  left: 0;
  bottom: 54px;
  width: min(360px, calc(100vw - 28px));
  max-height: min(640px, calc(100vh - 90px));
  overflow: auto;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  padding: 16px 18px;
  box-shadow: 0 18px 40px #30183e2e;
  font-size: 14px;
}
header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
h2 {
  font-size: 18px;
}
h3 {
  font-size: 14px;
  margin: 14px 0 6px;
}
.proto__note {
  color: var(--muted);
  font-size: 13px;
  margin-bottom: 10px;
}
fieldset {
  border: 1px solid var(--line);
  border-radius: 10px;
  margin: 0 0 10px;
  padding: 8px 12px 10px;
}
legend {
  font-weight: 700;
  padding: 0 4px;
}
fieldset label {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 36px;
}
fieldset .select {
  min-height: 40px;
  width: auto;
  flex: 1;
}
ul {
  margin: 0;
  padding-left: 18px;
}
li {
  margin: 6px 0;
}
li a {
  color: var(--admin-600);
}
</style>
