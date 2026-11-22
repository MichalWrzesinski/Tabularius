<script setup lang="ts">
import { OPERATION_LABEL } from '../../lib/status'

const ops = [
  {
    id: 'sales_report' as const,
    needs: 'Okres (zakończone dni) i zakres klientów; opcjonalnie producent lub kategoria',
    out: 'Pozycje, sumy na klienta i suma całkowita — PLN netto po korektach, bez anulowanych',
  },
  {
    id: 'parts_catalog' as const,
    needs: 'Jednoznacznie wskazany producent',
    out: 'Aktywne części: numer, nazwa, kategoria, jednostka, cena katalogowa netto',
  },
  {
    id: 'customer_discount_simulation' as const,
    needs: 'Klient z zakresu pracownika, okres, polityka rabatowa',
    out: 'Podstawa, próg, stawka i symulowany rabat — oferta niezatwierdzona',
  },
]
</script>

<template>
  <div>
    <div class="pagehead">
      <div>
        <h1>Dostępne dane</h1>
        <p>Zamknięty katalog operacji z profilu integracji demo-autoparts v1.</p>
      </div>
    </div>
    <div class="stats x-ops">
      <section v-for="o in ops" :key="o.id" class="stat">
        <b style="font-size: 16px">{{ OPERATION_LABEL[o.id] }}</b>
        <div class="param" style="margin-top: 18px">
          <small>WYMAGANE DANE</small>
          <p>{{ o.needs }}</p>
        </div>
        <div class="param" style="margin-top: 16px">
          <small>WYNIK</small>
          <p>{{ o.out }}</p>
        </div>
      </section>
    </div>
    <div class="x-alert neutral">
      Polityka rabatowa DEMO-RABAT 2026.1 i wszystkie dane są fikcyjne. Tabularius nie zmienia cen, umów, zamówień ani danych klientów.
      Profil zmienia zaufany operator w repozytorium.
    </div>
  </div>
</template>

<style scoped>
.x-ops {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.param small {
  display: block;
  font-size: 11px;
  color: #8f7f9c;
  margin-bottom: 6px;
}
.param p {
  font-size: 14px;
  line-height: 1.5;
}
@media (max-width: 860px) {
  .x-ops {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
