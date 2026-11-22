<script setup lang="ts">
// Mockup: docs/design/mockups/{desktop,mobile}/04-zgloszenie.html. States the mockup does not show
// (refusal, error, interventions) reuse its cards, params and buttons. Correspondence arrives with mail (TASK-006+).
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import AsyncBlock from '../../components/AsyncBlock.vue'
import CsvPreview from '../../components/CsvPreview.vue'
import Ico from '../../components/Ico.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { formatDateTime } from '../../lib/format'
import { errorMessage } from '../../lib/http'
import { ISSUE_LABEL, OPERATION_LABEL } from '../../lib/status'
import { openTicketNumber, ticketTitle } from '../../lib/ticket'
import { downloadFile, getFile, getTicket, reasonLabel, rejectTicket, retryTicket, RETRYABLE, sizeLabel } from '../../lib/tickets'
import type { ReportPreview } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'

const props = defineProps<{ id: string }>()
const { data: ticket, loading, error, reload } = useAsync(() => getTicket(props.id))
watch(
  () => props.id,
  () => void reload(),
)

// Queued and running tickets are polled until the worker finishes (no push channel yet).
let timer: ReturnType<typeof setTimeout> | undefined
watch(
  () => ticket.value?.status,
  (status) => {
    clearTimeout(timer)
    if (status === 'queued' || status === 'running') timer = setTimeout(() => void reload(), 1500)
  },
)
watch(
  () => ticket.value?.number,
  (n) => (openTicketNumber.value = n ?? null),
)
onBeforeUnmount(() => {
  clearTimeout(timer)
  openTicketNumber.value = null
})

const tab = ref<'messages' | 'history'>('messages')
const plan = computed(() => ticket.value?.plans[ticket.value.plans.length - 1] ?? null)
const file = ref<ReportPreview | null>(null)
const fileError = ref<string | null>(null)
watch(
  () => ticket.value?.fileId,
  async (id) => {
    fileError.value = null
    try {
      file.value = id ? await getFile(id) : null
    } catch (e) {
      file.value = null
      fileError.value = errorMessage(e)
    }
  },
  { immediate: true },
)

const busy = ref(false)
const actionError = ref<string | null>(null)

async function act(fn: () => Promise<unknown>) {
  actionError.value = null
  busy.value = true
  try {
    await fn()
    await reload()
  } catch (e) {
    actionError.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
function confirmReject() {
  if (window.confirm('Odrzucić zgłoszenie? Tej operacji nie można cofnąć.')) {
    void act(() => rejectTicket(ticket.value!.id))
  }
}

const canRetry = computed(() => ticket.value?.status === 'needs_intervention' && RETRYABLE.has(ticket.value.reason ?? ''))
// A ticket with an owner but no executable plan (mail waiting for interpretation, or in intervention).
const canSetPlan = computed(
  () =>
    !!ticket.value?.ownerId &&
    ['received', 'interpreting', 'needs_intervention'].includes(ticket.value.status) &&
    ticket.value.reason !== 'identity_unverified',
)
const canReject = computed(() => ['needs_intervention', 'queued', 'received'].includes(ticket.value?.status ?? ''))
const authState = computed(() => {
  const t = ticket.value
  if (!t) return ''
  if (t.reason === 'out_of_scope') return 'Odmowa: dane poza zakresem właściciela; nic nie ujawniono'
  if (t.reason === 'owner_inactive') return 'Odmowa: konto właściciela nieaktywne przed wykonaniem'
  if (['queued', 'running', 'completed', 'needs_intervention'].includes(t.status))
    return 'Autoryzowano w zakresie właściciela; ponowna kontrola przed wykonaniem i przy każdym pobraniu'
  return 'Jeszcze nie sprawdzano — plan niekompletny'
})
const authorLabel = {
  system: 'System (interpreter regułowy)',
  model: 'Interpretacja (model)',
  employee: 'Pracownik',
  admin: 'Administrator',
}
async function download() {
  actionError.value = null
  try {
    if (file.value) await downloadFile(file.value)
  } catch (e) {
    actionError.value = errorMessage(e)
  }
}
</script>

<template>
  <AsyncBlock :loading="loading && !ticket" :error="error" @retry="reload">
    <template v-if="ticket">
      <div class="tickethead">
        <RouterLink class="back" to="/admin/zgloszenia"><Ico name="arrow" />Wróć do zgłoszeń</RouterLink>
        <div class="tickettitle">
          <h1>{{ ticketTitle(ticket) }}</h1>
          <span class="id">#{{ ticket.number }}</span>
          <StatusBadge :status="ticket.status" :reason="ticket.reason" />
        </div>
      </div>

      <div v-if="ticket.status === 'needs_intervention'" class="x-alert error" role="status">
        <Ico name="alert" /><span
          ><b>{{ reasonLabel(ticket.reason) }}.</b> Pracownik nie otrzymał wyniku ani informacji o sukcesie. Wybierz działanie po
          prawej.</span
        >
      </div>
      <div v-else-if="ticket.status === 'rejected'" class="x-alert neutral" role="status">
        <Ico name="ban" /><span><b>Odrzucone:</b> {{ reasonLabel(ticket.reason) }}.</span>
      </div>
      <div v-else-if="ticket.status === 'needs_clarification'" class="x-alert wait" role="status">
        <Ico name="clock" /><span><b>Czeka na odpowiedź pracownika</b> — pytanie {{ ticket.clarificationRounds }} z 3.</span>
      </div>
      <div
        v-else-if="['interpreting', 'queued', 'running'].includes(ticket.status)"
        class="x-alert progress"
        role="status"
        aria-live="polite"
      >
        <Ico name="refresh" class="x-spin" /><span>{{
          ticket.status === 'interpreting' ? 'Trwa interpretacja wiadomości…' : 'Raport jest przygotowywany…'
        }}</span>
      </div>
      <div v-else-if="ticket.status === 'received'" class="x-alert neutral" role="status">
        <Ico name="mail" /><span><b>Przyjęte ze skrzynki.</b> Czeka na przypisanie nadawcy i interpretację prośby.</span>
      </div>
      <div v-else-if="ticket.status === 'awaiting_confirmation'" class="x-alert wait" role="status">
        <Ico name="shield" /><span><b>Czeka na potwierdzenie</b> pracownika po zalogowaniu (tryb produkcyjny).</span>
      </div>

      <div class="tabs" role="tablist" aria-label="Szczegóły zgłoszenia">
        <button
          id="tab-msg"
          class="tab"
          :class="{ active: tab === 'messages' }"
          role="tab"
          type="button"
          :aria-selected="tab === 'messages'"
          aria-controls="panel-msg"
          @click="tab = 'messages'"
        >
          Korespondencja <small>{{ ticket.messages.length }}</small>
        </button>
        <button
          id="tab-hist"
          class="tab"
          :class="{ active: tab === 'history' }"
          role="tab"
          type="button"
          :aria-selected="tab === 'history'"
          aria-controls="panel-hist"
          @click="tab = 'history'"
        >
          Historia
        </button>
      </div>

      <div class="detailgrid">
        <div>
          <div v-if="tab === 'messages'" id="panel-msg" role="tabpanel" aria-labelledby="tab-msg">
            <article v-if="ticket.source === 'mail'" class="card message">
              <div class="messageheader">
                <div class="avatar" aria-hidden="true">@</div>
                <div>
                  <b>Wiadomość e-mail</b>
                  <small>{{ ticket.employeeName }}</small>
                </div>
                <time :datetime="ticket.receivedAt">{{ formatDateTime(ticket.receivedAt) }}</time>
              </div>
              <div class="mailbody">
                <p>
                  <i
                    >Treść, temat i adres nadawcy nie są pokazywane w panelu demo.{{
                      ticket.plans.length ? ' Rozpoznaną prośbę pokazują parametry raportu.' : ' Wiadomość czeka na interpretację.'
                    }}</i
                  >
                </p>
              </div>
            </article>
            <article v-else class="card message">
              <div class="messageheader">
                <div class="avatar" aria-hidden="true">A</div>
                <div>
                  <b>Zgłoszenie ręczne</b>
                  <small>utworzone przez administratora w imieniu: {{ ticket.employeeName }}</small>
                </div>
                <time :datetime="ticket.receivedAt">{{ formatDateTime(ticket.receivedAt) }}</time>
              </div>
              <div class="mailbody">
                <p>
                  Bez wiadomości e-mail i bez interpretacji: operacja i parametry zostały wybrane w formularzu. Wynik jest dostępny
                  pracownikowi w „Moich raportach”.
                </p>
              </div>
            </article>
            <div v-if="fileError" class="x-alert error x-section" role="alert">
              <Ico name="alert" /><span>{{ fileError }}</span>
            </div>

            <template v-if="file">
              <div class="card file">
                <div class="fileicon"><Ico name="file" :width="2" /></div>
                <div>
                  <b>{{ file.name }}</b
                  ><small>{{ sizeLabel(file) }}</small>
                </div>
                <button class="cta" type="button" @click="download"><Ico name="download" /> Pobierz CSV</button>
              </div>
              <div class="successline"><Ico name="check" />Raport jest gotowy do pobrania.</div>
              <details class="card x-section" style="padding: 18px 25px">
                <summary style="cursor: pointer; font-weight: 700; min-height: 32px">Podgląd wyniku</summary>
                <CsvPreview :file="file" style="margin-top: 14px" />
              </details>
            </template>
          </div>

          <div v-else id="panel-hist" role="tabpanel" aria-labelledby="tab-hist" class="card x-card-pad">
            <h3 class="x-h3">Wersje planu</h3>
            <p v-if="!ticket.plans.length" class="x-muted">Brak planu — interpretacja się nie powiodła.</p>
            <ol class="x-timeline" style="margin-bottom: 18px">
              <li v-for="p in ticket.plans" :key="p.version">
                <time :datetime="p.at">{{ formatDateTime(p.at) }} · {{ authorLabel[p.author] }}</time>
                <b>v{{ p.version }}</b> — {{ p.operation ? OPERATION_LABEL[p.operation] : 'brak operacji'
                }}<template v-for="x in p.params" :key="x.label">; {{ x.label.toLowerCase() }}: {{ x.value }}</template>
              </li>
            </ol>
            <div class="param">
              <small>AUTORYZACJA</small>
              <p>{{ authState }}</p>
            </div>
            <h3 class="x-h3" style="margin-top: 26px">Dziennik obsługi</h3>
            <ol class="x-timeline">
              <li v-for="(e, i) in ticket.events" :key="i">
                <time :datetime="e.at">{{ formatDateTime(e.at) }} · {{ e.actorName }}</time
                >{{ e.text }}
              </li>
            </ol>
          </div>
        </div>

        <div>
          <aside class="card params">
            <h3>Parametry raportu</h3>
            <div class="param">
              <small>OPERACJA</small>
              <p>{{ plan?.operation ? OPERATION_LABEL[plan.operation] : '—' }}</p>
            </div>
            <div v-for="p in plan?.params ?? []" :key="p.label" class="param">
              <small>{{ p.label.toUpperCase() }}</small>
              <p>{{ p.value }}</p>
            </div>
            <div v-if="plan?.issues.length" class="param">
              <small>DO WYJAŚNIENIA</small>
              <p v-for="i in plan.issues" :key="i">{{ ISSUE_LABEL[i] ?? i }}</p>
            </div>
          </aside>

          <section v-if="canRetry || canReject || canSetPlan" class="card params x-section" aria-labelledby="act-h">
            <h3 id="act-h">Działania</h3>
            <p class="x-muted" style="margin: -14px 0 18px">Wykonywane w zakresie właściciela zgłoszenia i zapisywane w historii.</p>
            <div v-if="actionError" class="x-alert error" role="alert">
              <Ico name="alert" /><span>{{ actionError }}</span>
            </div>
            <button v-if="canRetry" class="cta" type="button" :disabled="busy" @click="act(() => retryTicket(ticket!.id))">
              <Ico name="refresh" />Ponów wykonanie
            </button>
            <RouterLink v-else-if="canSetPlan" class="cta" :to="`/admin/zgloszenia/${ticket.id}/parametry`"
              ><Ico name="check" />Ustaw parametry</RouterLink
            >
            <button
              v-if="canReject"
              class="x-btn2 danger x-block"
              :style="canRetry || canSetPlan ? 'margin-top: 16px' : ''"
              type="button"
              :disabled="busy"
              @click="confirmReject"
            >
              <Ico name="ban" />Odrzuć zgłoszenie
            </button>
          </section>
          <div v-else-if="actionError" class="x-alert error x-section" role="alert">
            <Ico name="alert" /><span>{{ actionError }}</span>
          </div>

          <button class="historylink" type="button" @click="tab = 'history'">
            <Ico name="clock" />Zobacz historię obsługi <Ico name="arrow" />
          </button>
        </div>
      </div>
    </template>
  </AsyncBlock>
</template>
