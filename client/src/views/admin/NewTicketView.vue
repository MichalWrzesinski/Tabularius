<script setup lang="ts">
// No mockup for this form: built from the mockups' pagehead, params card, field and cta patterns (same as „Pracownicy”).
// Manual ticket (TASK-005): the administrator picks the owner, an operation from the catalog and its parameters.
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AsyncBlock from '../../components/AsyncBlock.vue'
import Ico from '../../components/Ico.vue'
import { listAccounts } from '../../lib/accounts'
import { errorMessage } from '../../lib/http'
import { emptyForm, formProblem, MONTHS, parametersOf, PERIOD_OPTIONS } from '../../lib/ticketForm'
import { createTicket, getCatalog, ownerCustomers } from '../../lib/tickets'
import type { CustomerRef } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'

const router = useRouter()
const catalog = useAsync(getCatalog)
const accounts = useAsync(listAccounts)
const owners = computed(() => (accounts.data.value ?? []).filter((a) => a.role === 'rep' && a.status === 'active'))

const ownerId = ref('')
const form = ref(emptyForm())
const customers = ref<CustomerRef[]>([])
const customersError = ref<string | null>(null)
watch(ownerId, async (id) => {
  customers.value = []
  customersError.value = null
  form.value.customers = []
  form.value.customer = ''
  if (!id) return
  try {
    customers.value = await ownerCustomers(id)
  } catch (e) {
    customersError.value = errorMessage(e)
  }
})

const usesPeriod = computed(() => form.value.operation !== 'parts_catalog')
const years = computed(() => {
  const y = new Date().getFullYear()
  return [y, y - 1, y - 2]
})
const busy = ref(false)
const problem = ref<string | null>(null)

async function submit() {
  problem.value = formProblem(form.value, ownerId.value)
  if (problem.value) return
  busy.value = true
  try {
    const ticket = await createTicket(ownerId.value, form.value.operation, parametersOf(form.value))
    void router.push(`/admin/zgloszenia/${ticket.id}`)
  } catch (e) {
    problem.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <div class="tickethead">
      <RouterLink class="back" to="/admin/zgloszenia"><Ico name="arrow" />Wróć do zgłoszeń</RouterLink>
    </div>
    <div class="pagehead">
      <div>
        <h1>Nowe zgłoszenie</h1>
        <p>Zgłoszenie bez wiadomości e-mail: wybierz pracownika, operację z katalogu i parametry.</p>
      </div>
    </div>

    <AsyncBlock
      :loading="(catalog.loading.value && !catalog.data.value) || (accounts.loading.value && !accounts.data.value)"
      :error="catalog.error.value ?? accounts.error.value"
      @retry="() => (catalog.reload(), accounts.reload())"
    >
      <form v-if="catalog.data.value" class="card params" novalidate @submit.prevent="submit">
        <h3>Parametry raportu</h3>
        <p class="x-muted" style="margin: -14px 0 18px">
          Raport powstaje w zakresie klientów wybranego pracownika; uprawnienia są sprawdzane ponownie przed wykonaniem i przy pobraniu.
        </p>
        <div v-if="problem" class="x-alert error" role="alert">
          <Ico name="alert" /><span>{{ problem }}</span>
        </div>

        <div class="x-grid2">
          <div class="field">
            <label for="nt-owner">Pracownik</label>
            <select id="nt-owner" v-model="ownerId" class="x-native" style="height: 62px">
              <option value="" disabled>{{ owners.length ? 'Wybierz pracownika' : 'Brak aktywnych kont handlowców' }}</option>
              <option v-for="a in owners" :key="a.id" :value="a.id">{{ a.name }} ({{ a.repCode }})</option>
            </select>
          </div>
          <div class="field">
            <label for="nt-op">Operacja</label>
            <select id="nt-op" v-model="form.operation" class="x-native" style="height: 62px">
              <option v-for="op in catalog.data.value.operations" :key="op.id" :value="op.id">{{ op.label }}</option>
            </select>
          </div>
        </div>

        <div v-if="customersError" class="x-alert error" role="alert">
          <Ico name="alert" /><span>{{ customersError }}</span>
        </div>

        <template v-if="usesPeriod">
          <div class="x-grid2">
            <div class="field">
              <label for="nt-period">Okres</label>
              <select id="nt-period" v-model="form.periodType" class="x-native" style="height: 62px">
                <option v-for="p in PERIOD_OPTIONS" :key="p.value" :value="p.value">{{ p.label }}</option>
              </select>
            </div>
            <div v-if="form.periodType === 'month'" class="field">
              <label for="nt-month">Miesiąc</label>
              <select id="nt-month" v-model.number="form.month" class="x-native" style="height: 62px">
                <option v-for="(m, i) in MONTHS" :key="m" :value="i + 1">{{ m }}</option>
              </select>
            </div>
            <div v-if="form.periodType === 'quarter'" class="field">
              <label for="nt-q">Kwartał</label>
              <select id="nt-q" v-model.number="form.quarter" class="x-native" style="height: 62px">
                <option v-for="q in 4" :key="q" :value="q">Q{{ q }}</option>
              </select>
            </div>
            <div v-if="form.periodType === 'month' || form.periodType === 'quarter'" class="field">
              <label for="nt-year">Rok</label>
              <select id="nt-year" v-model="form.year" class="x-native" style="height: 62px">
                <option :value="null">Ostatni zakończony</option>
                <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
              </select>
            </div>
            <div v-if="form.periodType === 'last_n_days'" class="field">
              <label for="nt-days">Liczba dni (do wczoraj)</label>
              <div class="input"><input id="nt-days" v-model.number="form.days" type="number" min="1" max="366" /></div>
            </div>
            <template v-if="form.periodType === 'explicit'">
              <div class="field">
                <label for="nt-start">Od</label>
                <div class="input"><input id="nt-start" v-model="form.start" type="date" /></div>
              </div>
              <div class="field">
                <label for="nt-end">Do (dzień zakończony)</label>
                <div class="input"><input id="nt-end" v-model="form.end" type="date" /></div>
              </div>
            </template>
          </div>
        </template>

        <template v-if="form.operation === 'sales_report'">
          <fieldset class="x-choice">
            <legend>Klienci</legend>
            <label><input v-model="form.scope" type="radio" value="all" /> Wszyscy przypisani klienci</label>
            <label><input v-model="form.scope" type="radio" value="selected" :disabled="!customers.length" /> Wybrani klienci</label>
          </fieldset>
          <fieldset v-if="form.scope === 'selected'" class="x-choice">
            <legend class="sr-only">Wybierz klientów</legend>
            <label v-for="c in customers" :key="c.code"
              ><input v-model="form.customers" type="checkbox" :value="c.code" /> {{ c.name }} ({{ c.code }})</label
            >
          </fieldset>
          <div class="x-grid2">
            <div class="field">
              <label for="nt-man">Producent (opcjonalnie)</label>
              <select id="nt-man" v-model="form.manufacturer" class="x-native" style="height: 62px">
                <option value="">Wszyscy</option>
                <option v-for="m in catalog.data.value.manufacturers" :key="m.code" :value="m.code">{{ m.name }}</option>
              </select>
            </div>
            <div class="field">
              <label for="nt-cat">Kategoria (opcjonalnie)</label>
              <select id="nt-cat" v-model="form.category" class="x-native" style="height: 62px">
                <option value="">Wszystkie</option>
                <option v-for="c in catalog.data.value.categories" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
          </div>
        </template>

        <div v-if="form.operation === 'parts_catalog'" class="field">
          <label for="nt-cat-man">Producent</label>
          <select id="nt-cat-man" v-model="form.manufacturer" class="x-native" style="height: 62px">
            <option value="" disabled>Wybierz producenta</option>
            <option v-for="m in catalog.data.value.manufacturers" :key="m.code" :value="m.code">{{ m.name }}</option>
          </select>
        </div>

        <div v-if="form.operation === 'customer_discount_simulation'" class="field">
          <label for="nt-cust">Klient</label>
          <select id="nt-cust" v-model="form.customer" class="x-native" style="height: 62px" :disabled="!customers.length">
            <option value="" disabled>{{ ownerId ? 'Wybierz klienta' : 'Najpierw wybierz pracownika' }}</option>
            <option v-for="c in customers" :key="c.code" :value="c.code">{{ c.name }} ({{ c.code }})</option>
          </select>
        </div>

        <button class="cta" type="submit" :disabled="busy"><Ico name="check" />{{ busy ? 'Tworzenie…' : 'Utwórz i uruchom' }}</button>
      </form>
    </AsyncBlock>
  </div>
</template>

<style scoped>
.x-grid2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 0 18px;
}
</style>
