<script setup lang="ts">
// Mockup: the owner's mockup 03-przeglad (docs/design/README.md)
import { computed } from 'vue'
import AsyncBlock from '../../components/AsyncBlock.vue'
import Ico from '../../components/Ico.vue'
import TicketList from '../../components/TicketList.vue'
import { formatLongDate } from '../../lib/format'
import { useAsync } from '../../lib/useAsync'
import { listTickets } from '../../lib/tickets'
import type { TicketStatus } from '../../lib/types'

const { data, loading, error, reload } = useAsync(listTickets)
const stats = computed(() => {
  const t = data.value ?? []
  const n = (...s: TicketStatus[]) => t.filter((x) => s.includes(x.status)).length
  return [
    { label: 'Nowe', hint: 'Oczekują na obsługę', value: n('received', 'interpreting'), icon: 'mail', filter: 'interpreting' },
    { label: 'W realizacji', hint: 'Raporty w przygotowaniu', value: n('queued', 'running'), icon: 'clock', filter: 'running' },
    { label: 'Do sprawdzenia', hint: 'Wymagają Twojej uwagi', value: n('needs_intervention'), icon: 'alert', filter: 'needs_intervention' },
    { label: 'Ukończone', hint: 'Gotowe do pobrania', value: n('completed'), icon: 'check', filter: 'completed' },
  ]
})
</script>

<template>
  <div>
    <div class="pagehead">
      <div>
        <h1>Przegląd zgłoszeń</h1>
        <p>Wiadomości, statusy i gotowe raporty — w jednym miejscu.</p>
      </div>
      <div class="date">{{ formatLongDate(new Date().toISOString()) }}</div>
    </div>
    <AsyncBlock :loading="loading && !data" :error="error" :skeleton-rows="6" @retry="reload">
      <div class="stats">
        <RouterLink
          v-for="s in stats"
          :key="s.label"
          class="stat"
          :to="{ path: '/admin/zgloszenia', query: { status: s.filter } }"
          style="text-decoration: none"
        >
          <div class="sym"><Ico :name="s.icon" /></div>
          <span class="num">{{ s.value }}</span>
          <b>{{ s.label }}</b>
          <small>{{ s.hint }}</small>
        </RouterLink>
      </div>
      <div class="sectionhead">
        <h2>Ostatnie zgłoszenia</h2>
        <RouterLink class="textlink" to="/admin/zgloszenia">Zobacz wszystkie <Ico name="arrow" /></RouterLink>
      </div>
      <TicketList v-if="data" :tickets="data" :per-page="5" />
    </AsyncBlock>
  </div>
</template>
