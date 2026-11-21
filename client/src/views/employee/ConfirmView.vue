<script setup lang="ts">
import { computed, ref } from 'vue'
import Ico from '../../components/Ico.vue'
import { formatDateTime } from '../../lib/format'
import { OPERATION_LABEL } from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import { cancelTicket, confirmTicket, getTicket } from '../../mock/api'

const props = defineProps<{ ticketId: string }>()
const { data: ticket, loading, error, reload } = useAsync(() => getTicket(props.ticketId))
const plan = computed(() => ticket.value?.plans[ticket.value.plans.length - 1])
const busy = ref(false)
const actionError = ref<string | null>(null)
async function run(fn: (id: string) => Promise<unknown>) {
  busy.value = true
  actionError.value = null
  try {
    await fn(props.ticketId)
  } catch (e) {
    actionError.value = e instanceof Error ? e.message : 'Nie udało się zapisać decyzji.'
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
      <Ico name="shield" /><span
        >Adres nadawcy e-maila nie potwierdza tożsamości, dlatego prosimy o potwierdzenie po zalogowaniu (tryb produkcyjny).</span
      >
    </div>
    <div v-if="loading && !ticket" class="x-skel" style="height: 260px" aria-busy="true" />
    <div v-else-if="error" class="x-alert error" role="alert">
      <Ico name="alert" /><span>{{ error }} <button class="x-btn2 x-small" type="button" @click="reload">Spróbuj ponownie</button></span>
    </div>
    <div v-else-if="ticket" class="card params" style="max-width: 640px">
      <template v-if="ticket.status === 'awaiting_confirmation'">
        <h3>{{ plan?.operation ? OPERATION_LABEL[plan.operation] : 'Prośba' }}</h3>
        <div class="param">
          <small>WIADOMOŚĆ</small>
          <p>#{{ ticket.id }} · {{ formatDateTime(ticket.receivedAt) }}</p>
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
          <button class="cta blue" type="button" :disabled="busy" @click="run(confirmTicket)">
            <Ico name="check" />Potwierdź i przygotuj raport
          </button>
          <button class="x-btn2" type="button" :disabled="busy" @click="run(cancelTicket)"><Ico name="close" />To nie ja — anuluj</button>
        </div>
      </template>
      <template v-else-if="ticket.status === 'rejected'">
        <h3>Prośba anulowana</h3>
        <p class="x-muted" style="margin-bottom: 18px">Nie przygotujemy raportu. Administrator zobaczy informację w historii zgłoszenia.</p>
        <RouterLink class="x-btn2" to="/moje-raporty">Moje raporty</RouterLink>
      </template>
      <div v-else role="status">
        <h3>{{ ticket.status === 'completed' ? 'Raport gotowy' : 'Potwierdzone — przygotowujemy raport' }}</h3>
        <p v-if="ticket.status !== 'completed'" class="x-muted">To potrwa chwilę. Otrzymasz też wiadomość e-mail.</p>
        <RouterLink v-if="ticket.fileId" class="cta blue" :to="`/moje-raporty/${ticket.fileId}`"
          >Otwórz raport <Ico name="arrow" :width="2"
        /></RouterLink>
      </div>
    </div>
  </div>
</template>
