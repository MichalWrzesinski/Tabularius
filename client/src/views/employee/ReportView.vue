<script setup lang="ts">
// Opened from the e-mail link after login; download only on click (brief §8). Patterns from 04-zgloszenie.html.
import { watch } from 'vue'
import CsvPreview from '../../components/CsvPreview.vue'
import Ico from '../../components/Ico.vue'
import { csvSizeLabel, downloadCsv } from '../../lib/download'
import { formatDateTime } from '../../lib/format'
import { OPERATION_LABEL } from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import { getFile } from '../../mock/api'

const props = defineProps<{ fileId: string }>()
const { data: file, loading, error, reload } = useAsync(() => getFile(props.fileId))
watch(
  () => props.fileId,
  () => void reload(),
)
</script>

<template>
  <div>
    <div class="tickethead">
      <RouterLink class="back" to="/moje-raporty"><Ico name="arrow" />Wróć do moich raportów</RouterLink>
    </div>

    <div v-if="loading && !file" aria-busy="true">
      <span class="sr-only" role="status">Ładowanie raportu…</span>
      <div class="x-skel" style="height: 48px; width: 55%" />
      <div class="x-skel" style="height: 260px" />
    </div>

    <div v-else-if="error" class="card x-empty" role="alert">
      <span class="sym"><Ico name="lock" /></span>
      <h3>Ten raport jest niedostępny</h3>
      <p>Raport nie istnieje, wygasł albo nie należy do Ciebie. Jeśli to Twój raport, wyślij prośbę ponownie.</p>
      <RouterLink class="cta blue" to="/moje-raporty" style="margin-top: 18px">Moje raporty</RouterLink>
    </div>

    <template v-else-if="file">
      <div class="tickettitle" style="margin-top: 0">
        <h1>{{ OPERATION_LABEL[file.operation] }}</h1>
        <span class="badge done">Gotowy</span>
      </div>
      <p class="x-muted" style="margin: 10px 0 26px; font-size: 14px">{{ file.periodLabel }} · {{ file.scopeLabel }}</p>
      <div v-if="file.operation === 'customer_discount_simulation'" class="x-alert wait">
        <Ico name="alert" /><span
          ><b>Symulacja — oferta niezatwierdzona.</b> Obliczono według fikcyjnej polityki DEMO-RABAT 2026.1; nie zmienia cen ani umów.</span
        >
      </div>

      <div class="detailgrid">
        <div>
          <div class="card file" style="margin-top: 0">
            <div class="fileicon"><Ico name="file" :width="2" /></div>
            <div>
              <b>{{ file.name }}</b
              ><small>{{ csvSizeLabel(file) }}</small>
            </div>
            <button class="cta blue" type="button" @click="downloadCsv(file)"><Ico name="download" /> Pobierz CSV</button>
          </div>
          <div class="successline"><Ico name="check" />Raport jest gotowy do pobrania.</div>
          <section class="card x-card-pad x-section" aria-labelledby="prev-h">
            <h3 id="prev-h" class="x-h3">Podgląd</h3>
            <CsvPreview :file="file" />
            <p class="x-muted" style="margin-top: 12px">
              Kwoty w PLN netto. Pola tekstowe zaczynające się od znaków formuły są poprzedzone apostrofem.
            </p>
          </section>
        </div>
        <div>
          <aside class="card params">
            <h3>Parametry raportu</h3>
            <div class="param">
              <small>OPERACJA</small>
              <p>{{ OPERATION_LABEL[file.operation] }}</p>
            </div>
            <div class="param">
              <small>OKRES</small>
              <p>{{ file.periodLabel }}</p>
            </div>
            <div class="param">
              <small>ZAKRES</small>
              <p>{{ file.scopeLabel }}</p>
            </div>
            <div class="param">
              <small>DOSTĘPNY DO</small>
              <p>{{ formatDateTime(file.expiresAt) }}</p>
            </div>
          </aside>
        </div>
      </div>
    </template>
  </div>
</template>
