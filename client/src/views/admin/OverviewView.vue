<script setup lang="ts">
import { ArrowRight, Check, CircleAlert, Clock, Mail } from 'lucide-vue-next'
import { computed } from 'vue'
import AsyncBlock from '../../components/AsyncBlock.vue'
import TicketList from '../../components/TicketList.vue'
import { formatLongDate } from '../../lib/format'
import { useAsync } from '../../lib/useAsync'
import { listTickets } from '../../mock/api'
import { NOW } from '../../mock/data'
import type { TicketStatus } from '../../mock/types'

const { data, loading, error, reload } = useAsync(listTickets)

const stats = computed(() => {
  const t = data.value ?? []
  const count = (...s: TicketStatus[]) => t.filter((x) => s.includes(x.status)).length
  return [
    { label: 'Nowe', hint: 'Przyjęte i w interpretacji', value: count('received', 'interpreting'), icon: Mail, filter: 'interpreting' },
    { label: 'W realizacji', hint: 'W kolejce lub generowane', value: count('queued', 'running'), icon: Clock, filter: 'running' },
    { label: 'Wymagają interwencji', hint: 'Twoja decyzja', value: count('needs_intervention'), icon: CircleAlert, filter: 'needs_intervention' },
    { label: 'Ukończone', hint: 'Raport gotowy', value: count('completed'), icon: Check, filter: 'completed' },
  ]
})
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>Przegląd zgłoszeń</h1>
        <p>Wiadomości, statusy i gotowe raporty — w jednym miejscu.</p>
      </div>
      <span class="chip">{{ formatLongDate(NOW) }}</span>
    </div>

    <AsyncBlock :loading="loading && !data" :error="error" :skeleton-rows="5" @retry="reload">
      <ul class="stats">
        <li v-for="s in stats" :key="s.label">
          <RouterLink class="stat card" :to="{ path: '/admin/zgloszenia', query: { status: s.filter } }">
            <span class="sym"><component :is="s.icon" aria-hidden="true" /></span>
            <span class="stat__num">{{ s.value }}</span>
            <b>{{ s.label }}</b>
            <small>{{ s.hint }}</small>
          </RouterLink>
        </li>
      </ul>

      <div class="sectionhead">
        <h2>Ostatnie zgłoszenia</h2>
        <RouterLink class="textlink" to="/admin/zgloszenia">Zobacz wszystkie<ArrowRight aria-hidden="true" /></RouterLink>
      </div>
      <TicketList v-if="data" :tickets="data" :per-page="5" />
    </AsyncBlock>
  </div>
</template>

<style scoped>
.stats {
  list-style: none;
  margin: 0 0 40px;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
}
.stat {
  position: relative;
  display: flex;
  flex-direction: column;
  padding: 22px;
  text-decoration: none;
  height: 100%;
}
.stat:hover {
  border-color: #cdbde3;
}
.stat .sym {
  margin-bottom: 18px;
}
.stat__num {
  position: absolute;
  right: 22px;
  top: 22px;
  font-size: 36px;
  font-weight: 700;
  line-height: 1;
}
.stat b {
  font-size: 14px;
}
.stat small {
  font-size: 12px;
  color: var(--muted);
  margin-top: 4px;
}
.sectionhead {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.sectionhead h2 {
  font-size: 23px;
  letter-spacing: -0.5px;
}
@media (max-width: 760px) {
  .stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 28px;
  }
  .stat {
    padding: 16px 14px;
  }
  .stat .sym {
    width: 38px;
    height: 38px;
    margin-bottom: 14px;
  }
  .stat__num {
    font-size: 28px;
    right: 14px;
    top: 18px;
  }
  .stat b {
    font-size: 13px;
  }
  .sectionhead {
    display: block;
  }
}
</style>
