<script setup lang="ts">
// Confirmation of a request sent by e-mail (production mode, ID-2). Patterns from the mockups' params card and cta.
import { computed, ref } from 'vue'
import Ico from '../../components/Ico.vue'
import { formatDateTime } from '../../lib/format'
import { errorMessage } from '../../lib/http'
import { OPERATION_LABEL } from '../../lib/status'
import { cancelMyTicket, confirmMyTicket, getMyTicket } from '../../lib/tickets'
import type { MyTicket } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'

const props = defineProps<{ ticketId: string }>()
const { data, loading, error, reload } = useAsync(() => getMyTicket(props.ticketId))
const updated = ref<MyTicket | null>(null)
const ticket = computed(() => updated.value ?? data.value)
const plan = computed(() => ticket.value?.plans[ticket.value.plans.length - 1])
const busy = ref(false)
const actionError = ref<string | null>(null)
async function run(fn: (id: string) => Promise<MyTicket>) {
  busy.value = true
  actionError.value = null
  try {
    updated.value = await fn(props.ticketId)
  } catch (e) {
    actionError.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <div class="pagehead">
      <div>
        <h1>Potwierdź prośbę</h1>
        <p>Przygotujemy raport dopiero po Twoim potwierdzeniu.</p>
      </div>
    </div>
    <div class="x-alert progress">
      <Ico name="shield" /><span>Adres nadawcy e-maila nie potwierdza tożsamości, dlatego prosimy o potwierdzenie po zalogowaniu.</span>
    </div>
    <div v-if="loading && !ticket" class="x-skel" style="height: 260px" aria-busy="true" />
    <div v-else-if="error" class="card x-empty" role="alert">
      <span class="sym"><Ico name="lock" /></span>
      <h3>Ta prośba jest niedostępna</h3>
      <p>{{ error }}</p>
      <button class="x-btn2" type="button" @click="reload">Spróbuj ponownie</button>
    </div>
    <div v-else-if="ticket" class="card params" style="max-width: 640px">
      <template v-if="ticket.status === 'awaiting_confirmation'">
        <h3>{{ plan?.operation ? OPERATION_LABEL[plan.operation] : 'Prośba' }}</h3>
        <div class="param">
          <small>WIADOMOŚĆ</small>
          <p>#{{ ticket.number }} · {{ formatDateTime(ticket.receivedAt) }}</p>
        </div>
        <div v-for="p in plan?.params ?? []" :key="p.label" class="param">
          <small>{{ p.label.toUpperCase() }}</small>
          <p>{{ p.value }}</p>
        </div>
        <p class="x-muted" style="margin: 4px 0 20px">Wynik będzie dostępny tylko dla Ciebie, po zalogowaniu.</p>
        <div v-if="actionError" class="x-alert error" role="alert">
          <Ico name="alert" /><span>{{ actionError }}</span>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 12px">
          <button class="cta blue" type="button" :disabled="busy" @click="run(confirmMyTicket)">
            <Ico name="check" />Potwierdź i przygotuj raport
          </button>
          <button class="x-btn2" type="button" :disabled="busy" @click="run(cancelMyTicket)"><Ico name="close" />To nie ja — anuluj</button>
        </div>
      </template>
      <template v-else-if="ticket.status === 'rejected'">
        <h3>{{ ticket.reason === 'confirmation_expired' ? 'Prośba wygasła' : 'Prośba anulowana' }}</h3>
        <p class="x-muted" style="margin-bottom: 18px">Nie przygotujemy raportu. Administrator zobaczy informację w historii zgłoszenia.</p>
        <RouterLink class="x-btn2" to="/moje-raporty">Moje raporty</RouterLink>
      </template>
      <div v-else role="status">
        <h3>{{ ticket.status === 'completed' ? 'Raport gotowy' : 'Potwierdzone — przygotowujemy raport' }}</h3>
        <p v-if="ticket.status !== 'completed'" class="x-muted">To potrwa chwilę. Raport pojawi się w „Moich raportach”.</p>
        <RouterLink v-if="ticket.fileId" class="cta blue" :to="`/moje-raporty/${ticket.fileId}`"
          >Otwórz raport <Ico name="arrow" :width="2"
        /></RouterLink>
        <RouterLink v-else class="x-btn2" to="/moje-raporty">Moje raporty</RouterLink>
      </div>
    </div>
  </div>
</template>
