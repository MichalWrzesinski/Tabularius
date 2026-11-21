<script setup lang="ts">
// Mockup: docs/design/mockups/{desktop,mobile}/04-zgloszenie.html. States the mockup does not show
// (clarification, refusal, error, interventions) reuse its cards, params and buttons.
import { computed, ref, watch } from 'vue'
import AsyncBlock from '../../components/AsyncBlock.vue'
import CsvPreview from '../../components/CsvPreview.vue'
import Ico from '../../components/Ico.vue'
import MailBody from '../../components/MailBody.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { csvSizeLabel, downloadCsv } from '../../lib/download'
import { formatDate, formatDateTime, formatTime } from '../../lib/format'
import { ISSUE_LABEL, OPERATION_LABEL } from '../../lib/status'
import { initials, ticketPerson, ticketTitle } from '../../lib/ticket'
import { useAsync } from '../../lib/useAsync'
import {
  adminChooseCustomer, adminReject, adminResolveSales, adminRetryExecution, adminRetryInterpretation, getFile, getTicket, proto, simulateReply,
} from '../../mock/api'
import type { Message, ReportFile } from '../../mock/types'

const props = defineProps<{ id: string }>()
const { data: ticket, loading, error, reload } = useAsync(() => getTicket(props.id))
watch(() => props.id, () => void reload())

const tab = ref<'messages' | 'history'>('messages')
const plan = computed(() => ticket.value?.plans[ticket.value.plans.length - 1] ?? null)
const file = ref<ReportFile | null>(null)
watch(() => ticket.value?.fileId, async (id) => {
  file.value = id ? await getFile(id) : null
}, { immediate: true })

const busy = ref(false)
const actionError = ref<string | null>(null)
const replyText = ref('Za październik')
const month = ref<'2026-09' | '2026-10'>('2026-09')
const months = { '2026-09': ['01–30.09.2026', '2026-09-01', '2026-09-30'], '2026-10': ['01–31.10.2026', '2026-10-01', '2026-10-31'] } as const
const customer = ref('')

async function act(fn: () => Promise<unknown>) {
  actionError.value = null
  busy.value = true
  try {
    await fn()
  } catch (e) {
    actionError.value = e instanceof Error ? e.message : 'Nie udało się wykonać operacji.'
  } finally {
    busy.value = false
  }
}
function confirmReject() {
  if (window.confirm('Odrzucić zgłoszenie? Pracownik otrzyma informację z szablonu. Tej operacji nie można cofnąć.')) {
    void act(() => adminReject(ticket.value!.id))
  }
}

const isDataError = computed(() => !!ticket.value?.reason?.startsWith('Błąd źródła danych'))
const authState = computed(() => {
  const t = ticket.value
  if (!t) return ''
  if (plan.value?.issues.includes('out_of_scope_customer')) return 'Odmowa: klient poza zakresem właściciela; nic nie ujawniono'
  if (plan.value?.issues.includes('out_of_catalog')) return 'Nie dotyczy: operacja spoza katalogu, dane nie były odczytywane'
  if (['queued', 'running', 'completed'].includes(t.status) || isDataError.value) return 'Autoryzowano w zakresie właściciela; ponowna kontrola przed wykonaniem i pobraniem'
  if (t.status === 'awaiting_confirmation') return 'Czeka na potwierdzenie pracownika po zalogowaniu'
  return 'Jeszcze nie sprawdzano — plan niekompletny'
})
const authorLabel = { system: 'System', model: 'Interpretacja (model)', employee: 'Pracownik', admin: 'Administrator' }
const kindLabel: Record<Message['kind'], string> = {
  request: 'Prośba', reply: 'Odpowiedź', question: 'Pytanie z szablonu', notification: 'Powiadomienie', refusal: 'Odmowa z szablonu', confirmation_request: 'Prośba o potwierdzenie',
}
const stamp = (iso: string) => `${formatDate(iso)}   ${formatTime(iso)}`
</script>

<template>
  <AsyncBlock :loading="loading && !ticket" :error="error" @retry="reload">
    <template v-if="ticket">
      <div class="tickethead">
        <RouterLink class="back" to="/admin/zgloszenia"><Ico name="arrow" />Wróć do zgłoszeń</RouterLink>
        <div class="tickettitle">
          <h1>{{ ticketTitle(ticket) }}</h1>
          <span class="id">#{{ ticket.id }}</span>
          <StatusBadge :status="ticket.status" :reason="ticket.reason" />
        </div>
      </div>

      <div v-if="ticket.status === 'needs_intervention'" class="x-alert error" role="status"><Ico name="alert" /><span><b>{{ ticket.reason }}.</b> Pracownik nie otrzymał wyniku ani informacji o sukcesie. Wybierz działanie po prawej.</span></div>
      <div v-else-if="ticket.status === 'rejected'" class="x-alert neutral" role="status"><Ico name="ban" /><span><b>Odrzucone:</b> {{ ticket.reason }}. Pracownik otrzymał odpowiedź z szablonu.</span></div>
      <div v-else-if="ticket.status === 'needs_clarification'" class="x-alert wait" role="status"><Ico name="clock" /><span><b>Czeka na odpowiedź pracownika</b> — pytanie {{ ticket.clarificationRounds }} z 3.</span></div>
      <div v-else-if="['interpreting', 'queued', 'running'].includes(ticket.status)" class="x-alert progress" role="status" aria-live="polite"><Ico name="refresh" class="x-spin" /><span>{{ ticket.status === 'interpreting' ? 'Trwa interpretacja wiadomości…' : 'Raport jest przygotowywany…' }}</span></div>
      <div v-else-if="ticket.status === 'awaiting_confirmation'" class="x-alert wait" role="status"><Ico name="shield" /><span><b>Czeka na potwierdzenie</b> pracownika po zalogowaniu (tryb produkcyjny).</span></div>

      <div class="tabs" role="tablist" aria-label="Szczegóły zgłoszenia">
        <button id="tab-msg" class="tab" :class="{ active: tab === 'messages' }" role="tab" type="button" :aria-selected="tab === 'messages'" aria-controls="panel-msg" @click="tab = 'messages'">Korespondencja <small>{{ ticket.messages.length }}</small></button>
        <button id="tab-hist" class="tab" :class="{ active: tab === 'history' }" role="tab" type="button" :aria-selected="tab === 'history'" aria-controls="panel-hist" @click="tab = 'history'">Historia</button>
      </div>

      <div class="detailgrid">
        <div>
          <div v-if="tab === 'messages'" id="panel-msg" role="tabpanel" aria-labelledby="tab-msg">
            <article v-for="(m, i) in ticket.messages" :key="m.id" class="card message" :class="{ 'x-section': i > 0 }">
              <div class="messageheader">
                <div class="avatar" aria-hidden="true">{{ m.direction === 'out' ? 'T' : proto.anonymize ? '#' : initials(ticket.employeeName) }}</div>
                <div>
                  <b>{{ m.direction === 'out' ? 'Tabularius' : ticketPerson(ticket) }}</b>
                  <small>{{ m.direction === 'out' ? kindLabel[m.kind] : proto.anonymize ? 'adres ukryty w demo publicznym' : ticket.employeeEmail }}</small>
                </div>
                <time :datetime="m.at">{{ stamp(m.at) }}</time>
              </div>
              <MailBody v-if="m.direction === 'out' || !proto.anonymize" :text="m.body" />
              <div v-else class="mailbody"><p><i>{{ kindLabel[m.kind] }} uczestnika. Treść wiadomości nie jest pokazywana w demo publicznym; rozpoznano: {{ plan?.operation ? OPERATION_LABEL[plan.operation] : plan?.issues.includes('out_of_catalog') ? 'prośbę spoza katalogu' : 'brak operacji' }}.</i></p></div>
            </article>

            <template v-if="file">
              <div class="card file">
                <div class="fileicon"><Ico name="file" :width="2" /></div>
                <div><b>{{ file.name }}</b><small>{{ csvSizeLabel(file) }}</small></div>
                <button class="cta" type="button" @click="downloadCsv(file)"><Ico name="download" /> Pobierz CSV </button>
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
                <b>v{{ p.version }}</b> — {{ p.operation ? OPERATION_LABEL[p.operation] : 'brak operacji' }}<template v-for="x in p.params" :key="x.label">; {{ x.label.toLowerCase() }}: {{ x.value }}</template>
              </li>
            </ol>
            <div class="param"><small>AUTORYZACJA</small><p>{{ authState }}</p></div>
            <h3 class="x-h3" style="margin-top: 26px">Dziennik obsługi</h3>
            <ol class="x-timeline">
              <li v-for="(e, i) in ticket.events" :key="i"><time :datetime="e.at">{{ formatDateTime(e.at) }} · {{ e.actorName }}</time>{{ e.text }}</li>
            </ol>
          </div>
        </div>

        <div>
          <aside class="card params">
            <h3>Parametry raportu</h3>
            <div class="param"><small>OPERACJA</small><p>{{ plan?.operation ? OPERATION_LABEL[plan.operation] : '—' }}</p></div>
            <div v-for="p in plan?.params ?? []" :key="p.label" class="param"><small>{{ p.label.toUpperCase() }}</small><p>{{ p.value }}</p></div>
            <div v-if="plan?.issues.length" class="param"><small>DO WYJAŚNIENIA</small><p v-for="i in plan.issues" :key="i">{{ ISSUE_LABEL[i] ?? i }}</p></div>
          </aside>

          <section v-if="ticket.status === 'needs_intervention'" class="card params x-section" aria-labelledby="act-h">
            <h3 id="act-h">Działania</h3>
            <p class="x-muted" style="margin: -14px 0 18px">Wykonywane w zakresie właściciela zgłoszenia i zapisywane w historii.</p>
            <div v-if="actionError" class="x-alert error" role="alert"><Ico name="alert" /><span>{{ actionError }}</span></div>

            <form v-if="ticket.candidates?.length" @submit.prevent="act(() => adminChooseCustomer(ticket!.id, customer))">
              <fieldset class="x-choice">
                <legend>Wybierz klienta z zakresu pracownika</legend>
                <label v-for="c in ticket.candidates" :key="c"><input v-model="customer" type="radio" name="customer" :value="c" /> {{ c }}</label>
              </fieldset>
              <button class="cta" type="submit" :disabled="busy || !customer"><Ico name="check" />Uruchom symulację</button>
            </form>

            <button v-else-if="isDataError" class="cta" type="button" :disabled="busy" @click="act(() => adminRetryExecution(ticket!.id))"><Ico name="refresh" />Ponów wykonanie</button>

            <template v-else>
              <button class="x-btn2 x-block" type="button" :disabled="busy" @click="act(() => adminRetryInterpretation(ticket!.id))"><Ico name="refresh" />Ponów interpretację</button>
              <form style="margin-top: 20px" @submit.prevent="act(() => adminResolveSales(ticket!.id, months[month][0], months[month][1], months[month][2]))">
                <div class="param"><small>LUB USTAW RĘCZNIE — OPERACJA</small><p>Raport sprzedaży</p></div>
                <label class="x-field"><span>Okres</span>
                  <select v-model="month" class="x-native"><option value="2026-09">01–30.09.2026</option><option value="2026-10">01–31.10.2026</option></select>
                </label>
                <button class="cta" type="submit" :disabled="busy"><Ico name="check" />Uruchom raport</button>
              </form>
            </template>
            <button class="x-btn2 danger x-block" style="margin-top: 16px" type="button" :disabled="busy" @click="confirmReject"><Ico name="ban" />Odrzuć zgłoszenie</button>
          </section>

          <section v-if="ticket.status === 'needs_clarification'" class="card params x-section x-proto" aria-labelledby="sim-h">
            <h3 id="sim-h">Odpowiedź pracownika</h3>
            <p class="x-muted" style="margin: -14px 0 16px">Prototyp: imituje odpowiedź w tym samym wątku e-mail.</p>
            <div v-if="actionError" class="x-alert error" role="alert"><Ico name="alert" /><span>{{ actionError }}</span></div>
            <form @submit.prevent="act(() => simulateReply(ticket!.id, replyText))">
              <label class="x-field"><span>Treść odpowiedzi</span><span class="input"><input v-model="replyText" type="text" maxlength="200" /></span></label>
              <button class="cta" type="submit" :disabled="busy || !replyText.trim()"><Ico name="send" />Wyślij odpowiedź</button>
            </form>
          </section>

          <button class="historylink" type="button" @click="tab = 'history'"><Ico name="clock" />Zobacz historię obsługi <Ico name="arrow" /></button>
        </div>
      </div>
    </template>
  </AsyncBlock>
</template>
