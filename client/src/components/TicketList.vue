<script setup lang="ts">
// Mockup: filters, tablewrap (desktop) and ticketcards + mobilepagination (mobile) from 03-przeglad.html.
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { formatDate, formatTime } from '../lib/format'
import { STATUS, STATUS_ORDER } from '../lib/status'
import { ticketPerson, ticketTitle } from '../lib/ticket'
import { proto } from '../mock/api'
import type { Ticket, TicketStatus } from '../mock/types'
import Ico from './Ico.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ tickets: Ticket[]; perPage?: number; initialStatus?: TicketStatus | '' }>()
const router = useRouter()
const query = ref('')
const status = ref<TicketStatus | ''>(props.initialStatus ?? '')
const page = ref(1)
const per = computed(() => props.perPage ?? 5)
watch(
  () => props.initialStatus,
  (s) => (status.value = s ?? ''),
)
watch([query, status], () => (page.value = 1))

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  return props.tickets.filter(
    (t) =>
      (!status.value || t.status === status.value) &&
      (!q || [t.id, ticketPerson(t), ticketTitle(t)].some((v) => v.toLowerCase().includes(q))),
  )
})
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / per.value)))
const visible = computed(() => filtered.value.slice((page.value - 1) * per.value, page.value * per.value))
const pageList = computed(() => {
  const n = pages.value
  const p = page.value
  const start = Math.max(1, Math.min(p - 1, n - 2))
  return Array.from({ length: Math.min(3, n) }, (_, i) => start + i)
})
const personCol = computed(() => (proto.anonymize ? 'UCZESTNIK' : 'PRACOWNIK'))
const when = (iso: string) => (formatDate(iso) === '15.11.2026' ? formatTime(iso) : formatDate(iso))
const open = (t: Ticket) => void router.push(`/admin/zgloszenia/${t.id}`)
</script>

<template>
  <div>
    <div class="filters" role="search">
      <label class="search">
        <Ico name="search" />
        <span class="sr-only">Szukaj zgłoszenia</span>
        <input
          v-model="query"
          type="search"
          :placeholder="proto.anonymize ? 'Szukaj zgłoszenia lub uczestnika' : 'Szukaj zgłoszenia lub pracownika'"
        />
      </label>
      <label class="filter">
        <span class="sr-only">Status</span>
        <select v-model="status" class="x-select">
          <option value="">Wszystkie statusy</option>
          <option v-for="s in STATUS_ORDER" :key="s" :value="s">{{ STATUS[s].label }}</option>
        </select>
        <Ico name="chevron" />
      </label>
    </div>

    <div v-if="!filtered.length" class="card x-empty">
      <span class="sym"><Ico name="mail" /></span>
      <h3>Brak zgłoszeń</h3>
      <p>{{ query || status ? 'Żadne zgłoszenie nie pasuje do filtrów.' : 'Nowe zgłoszenia pojawią się tu po odebraniu wiadomości.' }}</p>
    </div>

    <template v-else>
      <div class="tablewrap">
        <table>
          <caption class="sr-only">
            Zgłoszenia
          </caption>
          <thead>
            <tr>
              <th scope="col">NUMER</th>
              <th scope="col">{{ personCol }}</th>
              <th scope="col">TEMAT</th>
              <th scope="col">STATUS</th>
              <th scope="col">OTRZYMANO</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in visible" :key="t.id" @click="open(t)">
              <td>
                <RouterLink :to="`/admin/zgloszenia/${t.id}`" @click.stop>#{{ t.id }}</RouterLink>
              </td>
              <td>{{ ticketPerson(t) }}</td>
              <td>{{ ticketTitle(t) }}</td>
              <td><StatusBadge :status="t.status" :reason="t.reason" /></td>
              <td>{{ when(t.receivedAt) }}</td>
            </tr>
          </tbody>
        </table>
        <div class="tablefoot">
          <span aria-live="polite">Wyświetlono {{ visible.length }} z {{ filtered.length }} zgłoszeń</span>
          <nav class="pages" aria-label="Paginacja zgłoszeń">
            <button type="button" :disabled="page <= 1" aria-label="Poprzednia strona" @click="page--">‹</button>
            <button
              v-for="n in pageList"
              :key="n"
              type="button"
              :aria-current="n === page ? 'page' : undefined"
              :aria-label="`Strona ${n}`"
              @click="page = n"
            >
              {{ n }}
            </button>
            <button type="button" :disabled="page >= pages" aria-label="Następna strona" @click="page++">›</button>
          </nav>
        </div>
      </div>

      <div class="ticketcards">
        <RouterLink v-for="t in visible" :key="t.id" class="ticketcard" :to="`/admin/zgloszenia/${t.id}`">
          <div class="ticketmeta">
            <b>#{{ t.id }}</b
            ><time :datetime="t.receivedAt">{{ when(t.receivedAt) }}</time>
          </div>
          <h3>{{ ticketTitle(t) }}</h3>
          <p>{{ ticketPerson(t) }}</p>
          <div class="ticketbottom"><StatusBadge :status="t.status" :reason="t.reason" /><span aria-hidden="true">→</span></div>
        </RouterLink>
      </div>
      <nav class="mobilepagination" aria-label="Paginacja zgłoszeń">
        <p class="paginationcount">Wyświetlono {{ visible.length }} z {{ filtered.length }} zgłoszeń</p>
        <div v-if="pages > 1" class="paginationcontrols">
          <button class="pagebutton" type="button" :disabled="page <= 1" aria-label="Poprzednia strona" @click="page--">
            <span aria-hidden="true">←</span> Poprzednia
          </button>
          <span class="pageposition" aria-current="page"
            ><b>{{ page }}</b> / {{ pages }}</span
          >
          <button class="pagebutton next" type="button" :disabled="page >= pages" aria-label="Następna strona" @click="page++">
            Następna <span aria-hidden="true">→</span>
          </button>
        </div>
      </nav>
    </template>
  </div>
</template>
