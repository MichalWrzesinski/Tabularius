<script setup lang="ts">
// No mockup for this view: built from the mockups' pagehead, file card and badge patterns (teal employee palette).
import AsyncBlock from '../../components/AsyncBlock.vue'
import Ico from '../../components/Ico.vue'
import { formatDateTime } from '../../lib/format'
import { OPERATION_LABEL, STATUS } from '../../lib/status'
import { ticketTitle } from '../../lib/ticket'
import { useAsync } from '../../lib/useAsync'
import { myFiles, myPendingTickets } from '../../mock/api'
import { DEMO_MAILBOX, NOW } from '../../mock/data'

const files = useAsync(myFiles)
const pending = useAsync(myPendingTickets)
const soon = (iso: string) => new Date(iso).getTime() - new Date(NOW).getTime() < 2 * 86400000
</script>

<template>
  <div>
    <div class="pagehead">
      <div>
        <h1>Moje raporty</h1>
        <p>Raporty przygotowane na Twoje prośby. Pliki są dostępne przez 7 dni.</p>
      </div>
    </div>

    <section v-if="pending.data.value?.length" style="margin-bottom: 30px" aria-labelledby="att-h">
      <div class="sectionhead"><h2 id="att-h">Wymagają Twojej uwagi</h2></div>
      <ul class="x-list">
        <li v-for="t in pending.data.value" :key="t.id" class="card x-row">
          <div class="x-grow">
            <b>{{ ticketTitle(t) }}</b>
            <small>#{{ t.id }} · {{ formatDateTime(t.receivedAt) }}</small>
          </div>
          <span class="badge wait">{{ STATUS[t.status].label }}</span>
          <RouterLink v-if="t.status === 'awaiting_confirmation'" class="cta blue" :to="`/moje-raporty/potwierdz/${t.id}`"
            >Sprawdź i potwierdź <Ico name="arrow" :width="2"
          /></RouterLink>
          <span v-else class="x-muted">Odpowiedz na pytanie w wiadomości e-mail</span>
        </li>
      </ul>
    </section>

    <div class="sectionhead"><h2>Gotowe raporty</h2></div>
    <AsyncBlock :loading="files.loading.value && !files.data.value" :error="files.error.value" @retry="files.reload">
      <div v-if="files.data.value && !files.data.value.length" class="card x-empty">
        <span class="sym"><Ico name="mail" /></span>
        <h3>Nie masz jeszcze raportów</h3>
        <p>
          Wyślij prośbę na <b>{{ DEMO_MAILBOX }}</b
          >, np. „Przygotuj raport sprzedaży za październik 2026”.
        </p>
      </div>
      <ul v-else class="x-list">
        <li v-for="f in files.data.value ?? []" :key="f.id" class="card x-row">
          <div class="fileicon"><Ico name="file" :width="2" /></div>
          <div class="x-grow">
            <b>{{ OPERATION_LABEL[f.operation] }}</b>
            <span>{{ f.periodLabel }} · {{ f.scopeLabel }}</span>
            <small
              >Utworzono {{ formatDateTime(f.createdAt) }} ·
              <span :style="soon(f.expiresAt) ? 'color:#8a5f12;font-weight:700' : ''"
                >dostępny do {{ formatDateTime(f.expiresAt) }}</span
              ></small
            >
          </div>
          <RouterLink class="cta blue" :to="`/moje-raporty/${f.id}`">Otwórz raport <Ico name="arrow" :width="2" /></RouterLink>
        </li>
      </ul>
    </AsyncBlock>
  </div>
</template>
