<script setup lang="ts">
import { ArrowRight, BellRing, FileText, Inbox } from 'lucide-vue-next'
import AsyncBlock from '../../components/AsyncBlock.vue'
import { formatDateTime } from '../../lib/format'
import { OPERATION_LABEL, STATUS } from '../../lib/status'
import { ticketTitle } from '../../lib/ticket'
import { useAsync } from '../../lib/useAsync'
import { myFiles, myPendingTickets } from '../../mock/api'
import { DEMO_MAILBOX, NOW } from '../../mock/data'

const files = useAsync(myFiles)
const pending = useAsync(myPendingTickets)
const expiresSoon = (iso: string) => new Date(iso).getTime() - new Date(NOW).getTime() < 2 * 86400000
</script>

<template>
  <div>
    <div class="page-head">
      <div><h1>Moje raporty</h1><p>Raporty przygotowane na Twoje prośby. Pliki są dostępne przez 7 dni.</p></div>
    </div>

    <section v-if="pending.data.value?.length" class="attention" aria-labelledby="att-h">
      <h2 id="att-h"><BellRing aria-hidden="true" />Wymagają Twojej uwagi</h2>
      <ul>
        <li v-for="t in pending.data.value" :key="t.id" class="card pend">
          <div>
            <b>{{ ticketTitle(t) }}</b>
            <span>{{ STATUS[t.status].label }} · #{{ t.id }} · {{ formatDateTime(t.receivedAt) }}</span>
          </div>
          <RouterLink v-if="t.status === 'awaiting_confirmation'" class="btn btn--small" :to="`/moje-raporty/potwierdz/${t.id}`">Sprawdź i potwierdź<ArrowRight aria-hidden="true" /></RouterLink>
          <span v-else class="hint">Odpowiedz na pytanie w wiadomości e-mail</span>
        </li>
      </ul>
    </section>

    <AsyncBlock :loading="files.loading.value && !files.data.value" :error="files.error.value" @retry="files.reload">
      <div v-if="files.data.value && !files.data.value.length" class="card empty">
        <span class="sym"><Inbox aria-hidden="true" /></span>
        <h3>Nie masz jeszcze raportów</h3>
        <p>Wyślij prośbę na <b>{{ DEMO_MAILBOX }}</b>, np. „Sprzedaż za ostatni miesiąc dla moich klientów”.</p>
      </div>
      <ul v-else class="reports">
        <li v-for="f in files.data.value ?? []" :key="f.id">
          <RouterLink class="card rep" :to="`/moje-raporty/${f.id}`">
            <span class="sym"><FileText aria-hidden="true" /></span>
            <span class="rep__main">
              <b>{{ OPERATION_LABEL[f.operation] }}</b>
              <span>{{ f.periodLabel }} · {{ f.scopeLabel }}</span>
              <small>Utworzono {{ formatDateTime(f.createdAt) }} · <span :class="{ soon: expiresSoon(f.expiresAt) }">dostępny do {{ formatDateTime(f.expiresAt) }}</span></small>
            </span>
            <ArrowRight class="rep__arrow" aria-hidden="true" />
          </RouterLink>
        </li>
      </ul>
    </AsyncBlock>
  </div>
</template>

<style scoped>
.attention {
  margin-bottom: 28px;
}
.attention h2 {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 18px;
  margin-bottom: 12px;
}
.attention h2 svg {
  width: 20px;
  color: var(--st-wait-fg);
}
.attention ul,
.reports {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;
}
.pend {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 18px 20px;
  border-color: #ecd9a8;
  background: #fffcf3;
}
.pend b {
  display: block;
}
.pend span {
  font-size: 13px;
  color: var(--muted);
}
.hint {
  font-size: 13px;
}
.rep {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 18px 20px;
  text-decoration: none;
}
.rep:hover {
  border-color: #9fd0d2;
}
.rep .sym {
  background: var(--sun);
}
.rep__main {
  flex: 1;
  display: grid;
  gap: 3px;
  min-width: 0;
}
.rep__main > span {
  color: var(--ink-soft);
  font-size: 14px;
}
.rep__main small {
  color: var(--muted);
  font-size: 12px;
}
.soon {
  color: var(--st-wait-fg);
  font-weight: 700;
}
.rep__arrow {
  width: 20px;
  color: var(--emp-600);
  flex-shrink: 0;
}
</style>
