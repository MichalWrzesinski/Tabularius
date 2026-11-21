<script setup lang="ts">
import { ArrowLeft, Ban, CircleAlert, Clock, Download, FileText, FlaskConical, Info, RefreshCw, Send, ShieldCheck } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import AsyncBlock from '../../components/AsyncBlock.vue'
import CsvPreview from '../../components/CsvPreview.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { formatDateTime } from '../../lib/format'
import { downloadCsv } from '../../lib/download'
import { ISSUE_LABEL, OPERATION_LABEL } from '../../lib/status'
import { ticketPerson, ticketTitle } from '../../lib/ticket'
import { useAsync } from '../../lib/useAsync'
import {
  adminChooseCustomer, adminReject, adminResolveSales, adminRetryInterpretation, getFile, getTicket, proto, simulateReply,
} from '../../mock/api'
import type { Message, ReportFile } from '../../mock/types'

const props = defineProps<{ id: string }>()
const { data: ticket, loading, error, reload } = useAsync(() => getTicket(props.id))
watch(() => props.id, () => void reload())

const tab = ref<'messages' | 'history'>('messages')
const plan = computed(() => ticket.value?.plans[ticket.value.plans.length - 1] ?? null)
const actionError = ref<string | null>(null)
const busy = ref(false)

const file = ref<ReportFile | null>(null)
watch(() => ticket.value?.fileId, async (id) => {
  file.value = id ? await getFile(id) : null
}, { immediate: true })

const replyText = ref('Za październik')
const month = ref('2026-09')
const months: Record<string, [string, string, string]> = {
  '2026-09': ['1–30 września 2026', '2026-09-01', '2026-09-30'],
  '2026-10': ['1–31 października 2026', '2026-10-01', '2026-10-31'],
}
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

const authState = computed(() => {
  const t = ticket.value
  if (!t) return ''
  if (t.status === 'rejected' && plan.value?.issues.includes('out_of_scope_customer')) return 'Odmowa: klient poza zakresem właściciela'
  if (t.status === 'rejected' && plan.value?.issues.includes('out_of_catalog')) return 'Nie dotyczy: operacja spoza katalogu, nie odczytano danych'
  if (['queued', 'running', 'completed'].includes(t.status)) return 'Autoryzowano w zakresie właściciela; ponowna kontrola przed wykonaniem i pobraniem'
  if (t.status === 'awaiting_confirmation') return 'Plan zgodny z zakresem; czeka na potwierdzenie pracownika po zalogowaniu'
  return 'Jeszcze nie sprawdzano — brak kompletnego planu'
})

const deliveryLabel: Record<NonNullable<Message['delivery']>, string> = {
  accepted: 'przyjęte przez serwer poczty',
  pending: 'w kolejce wysyłki',
  unknown: 'wynik wysyłki nieznany — bez ponawiania',
  failed: 'nie wysłano',
}
const kindLabel: Record<Message['kind'], string> = {
  request: 'Prośba', reply: 'Odpowiedź', question: 'Pytanie', notification: 'Powiadomienie', refusal: 'Odmowa', confirmation_request: 'Prośba o potwierdzenie',
}
const authorLabel = { system: 'System', model: 'Interpretacja (model)', employee: 'Pracownik', admin: 'Administrator' }
</script>

<template>
  <div>
    <RouterLink class="textlink back" to="/admin/zgloszenia"><ArrowLeft aria-hidden="true" />Wróć do zgłoszeń</RouterLink>
    <AsyncBlock :loading="loading && !ticket" :error="error" @retry="reload">
      <template v-if="ticket">
        <div class="tickethead">
          <h1>{{ ticketTitle(ticket) }}</h1>
          <span class="tid">#{{ ticket.id }}</span>
          <StatusBadge :status="ticket.status" />
        </div>
        <p class="who">{{ ticketPerson(ticket) }} · otrzymano {{ formatDateTime(ticket.receivedAt) }}</p>

        <div v-if="ticket.status === 'needs_intervention'" class="alert alert--error banner" role="status">
          <CircleAlert aria-hidden="true" /><p><b>Wymaga interwencji.</b> {{ ticket.reason }}. Pracownik nie otrzymał wyniku ani informacji o sukcesie.</p>
        </div>
        <div v-else-if="ticket.status === 'rejected'" class="alert alert--neutral banner" role="status">
          <Ban aria-hidden="true" /><p><b>Odrzucone.</b> {{ ticket.reason }}.</p>
        </div>
        <div v-else-if="ticket.status === 'needs_clarification'" class="alert alert--warn banner" role="status">
          <Clock aria-hidden="true" /><p><b>Czeka na odpowiedź pracownika.</b> Pytanie {{ ticket.clarificationRounds }} z 3.</p>
        </div>
        <div v-else-if="ticket.status === 'interpreting' || ticket.status === 'queued' || ticket.status === 'running'" class="alert alert--info banner" role="status" aria-live="polite">
          <RefreshCw aria-hidden="true" class="spin" /><p>{{ ticket.status === 'interpreting' ? 'Trwa interpretacja wiadomości…' : 'Raport jest przygotowywany…' }}</p>
        </div>

        <div class="tabs" role="tablist" aria-label="Szczegóły zgłoszenia">
          <button id="tab-messages" class="tab" role="tab" type="button" :aria-selected="tab === 'messages'" aria-controls="panel-messages" @click="tab = 'messages'">
            Korespondencja ({{ ticket.messages.length }})
          </button>
          <button id="tab-history" class="tab" role="tab" type="button" :aria-selected="tab === 'history'" aria-controls="panel-history" @click="tab = 'history'">
            Historia ({{ ticket.events.length }})
          </button>
        </div>

        <div class="grid">
          <div class="col">
            <section v-if="tab === 'messages'" id="panel-messages" role="tabpanel" aria-labelledby="tab-messages">
              <p v-if="proto.mode === 'demo'" class="alert alert--neutral demo-note">
                <Info aria-hidden="true" />
                <span>Demo publiczne: treść, temat i adres nadawcy nie są pokazywane. Pełną korespondencję widzi tylko zaufany operator.</span>
              </p>
              <article v-for="m in ticket.messages" :key="m.id" class="card msg" :class="m.direction === 'out' ? 'msg--out' : ''">
                <header class="msg__head">
                  <span class="avatar" aria-hidden="true">{{ m.direction === 'out' ? 'T' : proto.mode === 'demo' ? '#' : ticket.employeeName.split(' ').map((x) => x[0]).join('') }}</span>
                  <div>
                    <b>{{ m.direction === 'out' ? 'Tabularius' : ticketPerson(ticket) }}</b>
                    <small v-if="m.direction === 'in' && proto.mode === 'production'">{{ ticket.employeeEmail }}</small>
                    <small v-else>{{ kindLabel[m.kind] }}{{ m.direction === 'out' && m.template ? ` · szablon ${m.template}` : '' }}</small>
                  </div>
                  <time :datetime="m.at">{{ formatDateTime(m.at) }}</time>
                </header>
                <p v-if="m.direction === 'out'" class="msg__body">{{ m.body }}</p>
                <p v-else-if="proto.mode === 'production'" class="msg__body msg__body--in">{{ m.body }}</p>
                <p v-else class="msg__body msg__hidden">
                  {{ kindLabel[m.kind] }} od uczestnika · rozpoznano:
                  {{ plan?.operation ? OPERATION_LABEL[plan.operation] : plan?.issues.includes('out_of_catalog') ? 'prośbę spoza katalogu' : 'brak' }}
                </p>
                <p v-if="m.delivery" class="msg__delivery"><Send aria-hidden="true" />Wysyłka: {{ deliveryLabel[m.delivery] }}</p>
              </article>

              <article v-if="file" class="card filecard">
                <span class="sym"><FileText aria-hidden="true" /></span>
                <div class="filecard__text">
                  <b>{{ file.name }}</b>
                  <small>CSV · ważny do {{ formatDateTime(file.expiresAt) }}</small>
                </div>
                <button class="btn btn--small" type="button" @click="downloadCsv(file)"><Download aria-hidden="true" />Pobierz CSV</button>
              </article>
              <details v-if="file" class="card preview">
                <summary>Podgląd wyniku</summary>
                <CsvPreview :file="file" />
              </details>
            </section>

            <section v-else id="panel-history" role="tabpanel" aria-labelledby="tab-history" class="card card__body">
              <h2 class="h3">Wersje planu</h2>
              <p v-if="!ticket.plans.length" class="muted">Brak planu — interpretacja się nie powiodła.</p>
              <ol class="versions">
                <li v-for="p in ticket.plans" :key="p.version">
                  <b>v{{ p.version }}</b> · {{ authorLabel[p.author] }} · {{ formatDateTime(p.at) }}<br />
                  <span class="muted">{{ p.operation ? OPERATION_LABEL[p.operation] : '—' }}<template v-for="x in p.params" :key="x.label">; {{ x.label }}: {{ x.value }}</template></span>
                </li>
              </ol>
              <h2 class="h3">Dziennik</h2>
              <ol class="timeline">
                <li v-for="(e, i) in ticket.events" :key="i">
                  <time :datetime="e.at">{{ formatDateTime(e.at) }} · {{ e.actorName }}</time>{{ e.text }}
                </li>
              </ol>
            </section>
          </div>

          <aside class="col">
            <section class="card card__body">
              <h2 class="h3">Parametry raportu</h2>
              <div class="kv"><small>Operacja</small><p>{{ plan?.operation ? OPERATION_LABEL[plan.operation] : '—' }}</p></div>
              <div v-for="p in plan?.params ?? []" :key="p.label" class="kv"><small>{{ p.label }}</small><p>{{ p.value }}</p></div>
              <div v-if="plan?.issues.length" class="kv"><small>Brakujące lub błędne</small><p v-for="i in plan.issues" :key="i">{{ ISSUE_LABEL[i] ?? i }}</p></div>
              <div class="kv"><small>Wersja planu</small><p>{{ plan ? `v${plan.version} · ${authorLabel[plan.author]}` : '—' }}</p></div>
              <div class="kv"><small>Autoryzacja</small><p class="auth"><ShieldCheck aria-hidden="true" />{{ authState }}</p></div>
            </section>

            <section v-if="ticket.status === 'needs_intervention'" class="card card__body actions" aria-labelledby="act-h">
              <h2 id="act-h" class="h3">Działania administratora</h2>
              <p class="muted small">Działania wykonują się w zakresie właściciela zgłoszenia i są zapisywane w historii.</p>
              <div v-if="actionError" class="alert alert--error" role="alert"><p>{{ actionError }}</p></div>

              <form v-if="ticket.candidates?.length" @submit.prevent="act(() => adminChooseCustomer(ticket!.id, customer))">
                <fieldset class="choice">
                  <legend>Wybierz klienta z zakresu pracownika</legend>
                  <label v-for="c in ticket.candidates" :key="c"><input v-model="customer" type="radio" name="customer" :value="c" required /> {{ c }}</label>
                </fieldset>
                <button class="btn btn--block" type="submit" :disabled="busy || !customer">Uruchom symulację</button>
              </form>

              <template v-else>
                <button class="btn btn--secondary btn--block" type="button" :disabled="busy" @click="act(() => adminRetryInterpretation(ticket!.id))">
                  <RefreshCw aria-hidden="true" />Ponów interpretację
                </button>
                <form class="manual" @submit.prevent="act(() => adminResolveSales(ticket!.id, ...months[month]!))">
                  <p class="label">Albo ustaw parametry ręcznie</p>
                  <div class="kv"><small>Operacja</small><p>Raport sprzedaży</p></div>
                  <label class="field">
                    <span class="label">Okres</span>
                    <select v-model="month" class="select">
                      <option value="2026-09">1–30 września 2026</option>
                      <option value="2026-10">1–31 października 2026</option>
                    </select>
                  </label>
                  <button class="btn btn--block" type="submit" :disabled="busy">Uruchom raport</button>
                </form>
              </template>
              <button class="textlink danger" type="button" :disabled="busy" @click="confirmReject()"><Ban aria-hidden="true" />Odrzuć zgłoszenie</button>
            </section>

            <section v-if="ticket.status === 'needs_clarification'" class="card card__body actions proto-box" aria-labelledby="sim-h">
              <h2 id="sim-h" class="h3"><FlaskConical aria-hidden="true" />Symulacja odpowiedzi</h2>
              <p class="muted small">Tylko w prototypie: imituje odpowiedź pracownika w tym samym wątku e-mail.</p>
              <div v-if="actionError" class="alert alert--error" role="alert"><p>{{ actionError }}</p></div>
              <form @submit.prevent="act(() => simulateReply(ticket!.id, replyText))">
                <label class="field">
                  <span class="label">Treść odpowiedzi</span>
                  <span class="input"><input v-model="replyText" type="text" required maxlength="200" /></span>
                </label>
                <button class="btn btn--block" type="submit" :disabled="busy || !replyText.trim()"><Send aria-hidden="true" />Wyślij odpowiedź</button>
              </form>
            </section>

            <button class="textlink histlink" type="button" @click="tab = 'history'"><Clock aria-hidden="true" />Zobacz historię obsługi</button>
          </aside>
        </div>
      </template>
    </AsyncBlock>
  </div>
</template>

<style scoped>
.back {
  margin-top: 20px;
}
.tickethead {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 16px;
  margin-top: 12px;
}
h1 {
  font-size: clamp(24px, 3.2vw, 32px);
  letter-spacing: -0.8px;
  line-height: 1.2;
}
.tid {
  color: var(--muted);
  font-size: 14px;
}
.who {
  color: var(--muted);
  font-size: 14px;
  margin: 8px 0 18px;
}
.banner {
  margin-bottom: 18px;
}
.spin {
  animation: spin 1.2s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
.grid {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
  gap: 24px;
  align-items: start;
}
.col {
  display: grid;
  gap: 18px;
  min-width: 0;
}
.h3 {
  font-size: 18px;
  margin-bottom: 18px;
  display: flex;
  gap: 8px;
  align-items: center;
}
.h3 svg {
  width: 20px;
}
.demo-note {
  margin-bottom: 14px;
}
.msg {
  padding: 22px 24px;
  margin-bottom: 14px;
}
.msg--out {
  background: #fcfbfe;
}
.msg__head {
  display: flex;
  align-items: center;
  gap: 12px;
}
.msg__head b {
  display: block;
  font-size: 15px;
}
.msg__head small {
  font-size: 12px;
  color: var(--muted);
  overflow-wrap: anywhere;
}
.msg__head time {
  margin-left: auto;
  font-size: 12px;
  color: var(--muted);
  white-space: nowrap;
}
.avatar {
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  flex-shrink: 0;
  border-radius: 12px;
  background: #ebe5fc;
  color: var(--admin-600);
  font-weight: 700;
}
.msg__body {
  margin-top: 16px;
  font-size: 15px;
  line-height: 1.75;
  color: var(--ink-soft);
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.msg__hidden {
  font-style: italic;
}
.msg__delivery {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-top: 12px;
  font-size: 12px;
  color: var(--muted);
}
.msg__delivery svg {
  width: 15px;
}
.filecard {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 20px 22px;
  flex-wrap: wrap;
}
.filecard__text {
  flex: 1;
  min-width: 180px;
}
.filecard__text b {
  display: block;
  font-size: 14px;
  overflow-wrap: anywhere;
}
.filecard__text small {
  color: var(--muted);
  font-size: 12px;
}
.preview {
  margin-top: 14px;
  padding: 14px 18px;
}
.preview summary {
  cursor: pointer;
  font-weight: 700;
  min-height: 32px;
}
.versions {
  margin: 0 0 22px;
  padding-left: 18px;
  font-size: 14px;
  line-height: 1.6;
}
.versions li + li {
  margin-top: 10px;
}
.muted {
  color: var(--muted);
}
.small {
  font-size: 13px;
  margin: -8px 0 14px;
}
.auth {
  display: flex;
  gap: 8px;
  font-size: 14px !important;
}
.auth svg {
  width: 18px;
  flex-shrink: 0;
  color: var(--admin-600);
}
.actions form + .textlink,
.actions .textlink.danger {
  margin-top: 10px;
}
.manual {
  margin-top: 18px;
  padding-top: 16px;
  border-top: 1px solid var(--line-soft);
}
.manual .kv {
  margin-bottom: 14px;
}
.label {
  display: block;
  font-weight: 700;
  font-size: 14px;
  margin-bottom: 8px;
}
.choice {
  border: 0;
  padding: 0;
  margin: 0 0 14px;
}
.choice legend {
  font-weight: 700;
  font-size: 14px;
  margin-bottom: 8px;
}
.choice label {
  display: flex;
  gap: 10px;
  align-items: center;
  min-height: 44px;
}
.danger {
  color: var(--st-error-fg);
}
.proto-box {
  border-style: dashed;
  border-color: #c9a75d;
}
.histlink {
  justify-self: center;
}
@media (max-width: 1100px) {
  .grid {
    grid-template-columns: minmax(0, 1fr);
  }
  aside.col {
    order: -1;
  }
}
@media (max-width: 640px) {
  .msg {
    padding: 18px;
  }
  .msg__head {
    flex-wrap: wrap;
  }
  .msg__head time {
    margin-left: 54px;
    width: 100%;
  }
  .filecard .btn {
    width: 100%;
  }
}
</style>
