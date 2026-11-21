<script setup lang="ts">
import { ArrowRight, Inbox, Search } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { formatDate, formatTime } from '../lib/format'
import { STATUS, STATUS_ORDER } from '../lib/status'
import { ticketPerson, ticketTitle } from '../lib/ticket'
import { proto } from '../mock/api'
import type { Ticket, TicketStatus } from '../mock/types'
import PagerNav from './PagerNav.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ tickets: Ticket[]; perPage?: number; initialStatus?: TicketStatus | '' }>()
const router = useRouter()

const query = ref('')
const status = ref<TicketStatus | ''>(props.initialStatus ?? '')
const page = ref(1)
const per = computed(() => props.perPage ?? 5)

watch(() => props.initialStatus, (s) => (status.value = s ?? ''))
watch([query, status], () => (page.value = 1))

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  return props.tickets.filter((t) => {
    if (status.value && t.status !== status.value) return false
    if (!q) return true
    return [t.id, ticketPerson(t), ticketTitle(t)].some((v) => v.toLowerCase().includes(q))
  })
})
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / per.value)))
const visible = computed(() => filtered.value.slice((page.value - 1) * per.value, page.value * per.value))
const personLabel = computed(() => (proto.mode === 'demo' ? 'Uczestnik' : 'Pracownik'))

function open(t: Ticket) {
  void router.push(`/admin/zgloszenia/${t.id}`)
}
function when(iso: string) {
  return formatDate(iso) === '15.11.2026' ? formatTime(iso) : formatDate(iso)
}
</script>

<template>
  <div>
    <div class="filters" role="search">
      <label class="search input">
        <Search aria-hidden="true" />
        <span class="sr-only">Szukaj zgłoszenia</span>
        <input v-model="query" type="search" :placeholder="`Szukaj zgłoszenia lub ${personLabel === 'Uczestnik' ? 'uczestnika' : 'pracownika'}`" />
      </label>
      <label class="statusfilter">
        <span class="sr-only">Filtruj po statusie</span>
        <select v-model="status" class="select">
          <option value="">Wszystkie statusy</option>
          <option v-for="s in STATUS_ORDER" :key="s" :value="s">{{ STATUS[s].label }}</option>
        </select>
      </label>
    </div>

    <div v-if="!filtered.length" class="card empty">
      <span class="sym"><Inbox aria-hidden="true" /></span>
      <h3>Brak zgłoszeń</h3>
      <p>{{ query || status ? 'Żadne zgłoszenie nie pasuje do filtrów.' : 'Nowe zgłoszenia pojawią się tu po odebraniu wiadomości.' }}</p>
    </div>

    <template v-else>
      <div class="table-wrap desktop-only">
        <table class="table">
          <caption class="sr-only">Zgłoszenia</caption>
          <thead>
            <tr><th scope="col">Numer</th><th scope="col">{{ personLabel }}</th><th scope="col">Temat</th><th scope="col">Status</th><th scope="col">Otrzymano</th></tr>
          </thead>
          <tbody>
            <tr v-for="t in visible" :key="t.id" class="row" @click="open(t)">
              <td><RouterLink class="idlink" :to="`/admin/zgloszenia/${t.id}`" @click.stop>#{{ t.id }}</RouterLink></td>
              <td>{{ ticketPerson(t) }}</td>
              <td>{{ ticketTitle(t) }}</td>
              <td><StatusBadge :status="t.status" /></td>
              <td class="muted">{{ when(t.receivedAt) }}</td>
            </tr>
          </tbody>
        </table>
        <PagerNav v-model:page="page" :pages="pages" :shown="visible.length" :total="filtered.length" noun="zgłoszeń" />
      </div>

      <ul class="cards mobile-only">
        <li v-for="t in visible" :key="t.id">
          <RouterLink class="tcard card" :to="`/admin/zgloszenia/${t.id}`">
            <span class="tcard__top"><b>#{{ t.id }}</b><span>{{ when(t.receivedAt) }}</span></span>
            <span class="tcard__title">{{ ticketTitle(t) }}</span>
            <span class="tcard__who">{{ ticketPerson(t) }}</span>
            <span class="tcard__bottom"><StatusBadge :status="t.status" /><ArrowRight aria-hidden="true" /></span>
          </RouterLink>
        </li>
      </ul>
      <div class="mobile-only">
        <PagerNav v-model:page="page" :pages="pages" :shown="visible.length" :total="filtered.length" noun="zgłoszeń" />
      </div>
    </template>
  </div>
</template>

<style scoped>
.filters {
  display: flex;
  gap: 16px;
  margin-bottom: 20px;
}
.search {
  flex: 1;
  background: var(--surface);
}
.statusfilter {
  width: 240px;
}
.statusfilter .select {
  min-height: 58px;
}
.row {
  cursor: pointer;
}
.idlink {
  font-weight: 700;
  color: var(--admin-600);
  text-decoration: none;
}
.idlink:hover {
  text-decoration: underline;
}
.muted {
  color: var(--muted);
}
.cards {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;
}
.tcard {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 18px;
  text-decoration: none;
}
.tcard__top {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: var(--muted);
}
.tcard__top b {
  color: var(--admin-600);
}
.tcard__title {
  font-weight: 700;
  font-size: 17px;
  line-height: 1.3;
}
.tcard__who {
  font-size: 14px;
  color: var(--ink-soft);
}
.tcard__bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 8px;
  color: var(--admin-600);
}
.tcard__bottom svg {
  width: 18px;
}
.mobile-only {
  display: none;
}
@media (max-width: 760px) {
  .filters {
    flex-direction: column;
    gap: 10px;
  }
  .statusfilter {
    width: 100%;
  }
  .desktop-only {
    display: none;
  }
  .mobile-only {
    display: block;
  }
  .cards.mobile-only {
    display: grid;
  }
}
</style>
