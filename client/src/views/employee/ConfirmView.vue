<script setup lang="ts">
import { Check, CircleCheck, ShieldCheck, X } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { OPERATION_LABEL } from '../../lib/status'
import { formatDateTime } from '../../lib/format'
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
  <div class="wrap">
    <div class="page-head"><div><h1>Potwierdź prośbę</h1><p>Przygotujemy raport dopiero po Twoim potwierdzeniu.</p></div></div>
    <p class="alert alert--info"><ShieldCheck aria-hidden="true" /><span>Tryb produkcyjny: adres nadawcy e-maila nie potwierdza tożsamości, dlatego prosimy o potwierdzenie po zalogowaniu.</span></p>

    <div v-if="loading && !ticket" class="skeleton" style="height: 240px; margin-top: 18px" aria-busy="true" />
    <div v-else-if="error" class="alert alert--error" role="alert"><p>{{ error }}</p><button class="btn btn--small btn--secondary" type="button" @click="reload">Spróbuj ponownie</button></div>

    <section v-else-if="ticket" class="card card__body plan">
      <template v-if="ticket.status === 'awaiting_confirmation'">
        <h2>{{ plan?.operation ? OPERATION_LABEL[plan.operation] : 'Prośba' }}</h2>
        <p class="muted">Wiadomość z {{ formatDateTime(ticket.receivedAt) }} · #{{ ticket.id }}</p>
        <dl>
          <div v-for="p in plan?.params ?? []" :key="p.label"><dt>{{ p.label }}</dt><dd>{{ p.value }}</dd></div>
        </dl>
        <p class="muted">Wynik będzie dostępny tylko dla Ciebie, po zalogowaniu.</p>
        <div v-if="actionError" class="alert alert--error" role="alert"><p>{{ actionError }}</p></div>
        <div class="buttons">
          <button class="btn" type="button" :disabled="busy" @click="run(confirmTicket)"><Check aria-hidden="true" />Potwierdź i przygotuj raport</button>
          <button class="btn btn--secondary" type="button" :disabled="busy" @click="run(cancelTicket)"><X aria-hidden="true" />To nie ja / anuluj</button>
        </div>
      </template>
      <div v-else-if="ticket.status === 'rejected'" class="done">
        <h2>Prośba anulowana</h2>
        <p>Nie przygotujemy raportu. Administrator zobaczy informację w historii zgłoszenia.</p>
        <RouterLink class="btn btn--secondary" to="/moje-raporty">Moje raporty</RouterLink>
      </div>
      <div v-else class="done" role="status">
        <CircleCheck aria-hidden="true" class="ok" />
        <h2>{{ ticket.status === 'completed' ? 'Raport gotowy' : 'Potwierdzone — przygotowujemy raport' }}</h2>
        <p v-if="ticket.status !== 'completed'">To potrwa chwilę. Otrzymasz też wiadomość e-mail.</p>
        <RouterLink v-if="ticket.fileId" class="btn" :to="`/moje-raporty/${ticket.fileId}`">Otwórz raport</RouterLink>
      </div>
    </section>
  </div>
</template>

<style scoped>
.wrap {
  max-width: 720px;
}
.plan {
  margin-top: 18px;
}
.plan h2 {
  font-size: 21px;
}
.muted {
  color: var(--muted);
  font-size: 14px;
  margin-top: 6px;
}
dl {
  margin: 18px 0;
  display: grid;
  gap: 14px;
}
dt {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--muted);
}
dd {
  margin: 2px 0 0;
  font-size: 16px;
}
.buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 20px;
}
.done {
  display: grid;
  gap: 10px;
  justify-items: start;
}
.ok {
  width: 36px;
  height: 36px;
  color: var(--emp-600);
}
@media (max-width: 640px) {
  .buttons .btn {
    width: 100%;
  }
}
</style>
