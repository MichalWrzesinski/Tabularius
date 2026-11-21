<script setup lang="ts">
import { OPERATION_LABEL } from '../../lib/status'

const ops = [
  { id: 'sales_report' as const, needs: 'Okres (zakończone dni) i zakres klientów; opcjonalnie producent lub kategoria', out: 'Pozycje, sumy na klienta i suma całkowita (PLN netto po korektach, bez anulowanych)' },
  { id: 'parts_catalog' as const, needs: 'Jednoznacznie wskazany producent', out: 'Aktywne części: numer, nazwa, kategoria, jednostka, cena katalogowa netto' },
  { id: 'customer_discount_simulation' as const, needs: 'Klient z Twojego zakresu, okres, polityka rabatowa', out: 'Podstawa, próg, stawka i symulowany rabat — oferta niezatwierdzona' },
]
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Dostępne dane</h1><p>Zamknięty katalog operacji z profilu integracji <b>demo-autoparts v1</b>. Zmiany wprowadza zaufany operator w repozytorium.</p></div></div>
    <div class="table-wrap">
      <table class="table">
        <caption class="sr-only">Katalog operacji</caption>
        <thead><tr><th scope="col">Operacja</th><th scope="col">Wymagane dane</th><th scope="col">Wynik</th></tr></thead>
        <tbody>
          <tr v-for="o in ops" :key="o.id"><th scope="row">{{ OPERATION_LABEL[o.id] }}</th><td>{{ o.needs }}</td><td>{{ o.out }}</td></tr>
        </tbody>
      </table>
    </div>
    <p class="alert alert--neutral note">Polityka rabatowa DEMO-RABAT 2026.1 i wszystkie dane są fikcyjne. Tabularius nie zmienia cen, umów, zamówień ani danych klientów.</p>
  </div>
</template>

<style scoped>
.note {
  margin-top: 18px;
}
th[scope='row'] {
  white-space: nowrap;
  padding: 16px 18px;
  border-top: 1px solid var(--line-soft);
}
@media (max-width: 760px) {
  .table thead {
    display: none;
  }
  .table tr,
  .table th[scope='row'],
  .table td {
    display: block;
    border: 0;
  }
  .table tr {
    border-top: 1px solid var(--line-soft);
    padding: 8px 0;
  }
  .table td,
  th[scope='row'] {
    padding: 6px 16px;
    white-space: normal;
  }
}
</style>
