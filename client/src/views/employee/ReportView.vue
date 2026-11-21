<script setup lang="ts">
import { ArrowLeft, Download, FileText, ShieldAlert, TriangleAlert } from 'lucide-vue-next'
import { computed, watch } from 'vue'
import CsvPreview from '../../components/CsvPreview.vue'
import { downloadCsv } from '../../lib/download'
import { formatDateTime } from '../../lib/format'
import { OPERATION_LABEL } from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import { getFile } from '../../mock/api'

const props = defineProps<{ fileId: string }>()
const { data: file, loading, error, reload } = useAsync(() => getFile(props.fileId))
watch(() => props.fileId, () => void reload())
const forbidden = computed(() => !!error.value)
</script>

<template>
  <div>
    <RouterLink class="textlink back" to="/moje-raporty"><ArrowLeft aria-hidden="true" />Moje raporty</RouterLink>

    <div v-if="loading && !file" aria-busy="true">
      <span class="sr-only">Ładowanie raportu…</span>
      <div class="skeleton" style="height: 40px; width: 60%; margin: 20px 0" />
      <div class="skeleton" style="height: 220px" />
    </div>

    <div v-else-if="forbidden" class="card card__body denied" role="alert">
      <span class="sym"><ShieldAlert aria-hidden="true" /></span>
      <h1>Ten raport jest niedostępny</h1>
      <p>Raport nie istnieje, wygasł albo nie należy do Ciebie. Jeśli to Twój raport, wyślij prośbę ponownie.</p>
      <RouterLink class="btn" to="/moje-raporty">Przejdź do moich raportów</RouterLink>
    </div>

    <template v-else-if="file">
      <div class="page-head">
        <div>
          <h1>{{ OPERATION_LABEL[file.operation] }}</h1>
          <p>{{ file.periodLabel }} · {{ file.scopeLabel }}</p>
        </div>
      </div>

      <p v-if="file.operation === 'customer_discount_simulation'" class="alert alert--warn sim">
        <TriangleAlert aria-hidden="true" /><span><b>Symulacja — oferta niezatwierdzona.</b> Obliczenie według fikcyjnej polityki DEMO-RABAT 2026.1; nie zmienia cen ani umów.</span>
      </p>

      <section class="card dl">
        <span class="sym"><FileText aria-hidden="true" /></span>
        <div class="dl__text">
          <b>{{ file.name }}</b>
          <small>CSV (UTF-8, separator „;”) · utworzono {{ formatDateTime(file.createdAt) }} · dostępny do {{ formatDateTime(file.expiresAt) }}</small>
        </div>
        <button class="btn" type="button" @click="downloadCsv(file)"><Download aria-hidden="true" />Pobierz CSV</button>
      </section>

      <section class="card card__body">
        <h2 class="h2">Podgląd</h2>
        <CsvPreview :file="file" />
        <p class="foot">Kwoty w PLN netto. Pola tekstowe zaczynające się od znaków formuły są poprzedzone apostrofem.</p>
      </section>
    </template>
  </div>
</template>

<style scoped>
.back {
  margin-top: 20px;
}
.denied {
  margin-top: 24px;
  display: grid;
  gap: 12px;
  justify-items: start;
}
.denied h1 {
  font-size: 22px;
}
.sim {
  margin-bottom: 16px;
}
.dl {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  padding: 20px 22px;
  margin-bottom: 18px;
}
.dl__text {
  flex: 1;
  min-width: 200px;
}
.dl__text b {
  display: block;
  overflow-wrap: anywhere;
}
.dl__text small {
  font-size: 12px;
  color: var(--muted);
}
.h2 {
  font-size: 18px;
  margin-bottom: 14px;
}
.foot {
  margin-top: 12px;
  font-size: 12px;
  color: var(--muted);
}
@media (max-width: 640px) {
  .dl .btn {
    width: 100%;
  }
}
</style>
