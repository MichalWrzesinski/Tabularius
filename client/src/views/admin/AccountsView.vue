<script setup lang="ts">
import { computed, ref } from 'vue'
import AsyncBlock from '../../components/AsyncBlock.vue'
import Ico from '../../components/Ico.vue'
import {
  blockAccount,
  createAccount,
  deleteAccount,
  listAccounts,
  listSalesReps,
  unblockAccount,
  type AdminAccount,
} from '../../lib/accounts'
import { formatDateTime } from '../../lib/format'
import { errorMessage } from '../../lib/http'
import { initials } from '../../lib/ticket'
import { useAsync } from '../../lib/useAsync'
import { proto } from '../../mock/api'

const { data, loading, error, reload } = useAsync(listAccounts)
const reps = useAsync(listSalesReps)
const freeReps = computed(() => (reps.data.value ?? []).filter((r) => !r.taken))
const formOpen = ref(false)
const name = ref('')
const email = ref('')
const rep = ref('')
const formError = ref<string | null>(null)
const notice = ref<string | null>(null)
const busy = ref(false)

async function submit() {
  formError.value = null
  if (!name.value.trim() || !/^\S+@\S+\.\S+$/.test(email.value.trim()) || !rep.value) {
    formError.value = 'Podaj imię i nazwisko, prawidłowy służbowy adres e-mail i wybierz handlowca.'
    return
  }
  busy.value = true
  try {
    const created = await createAccount(name.value.trim(), email.value.trim(), rep.value)
    notice.value =
      proto.mode === 'demo'
        ? `Konto ${created.name} utworzone. W demo wiadomość z hasłem jest przechwytywana i nie trafia do adresata.`
        : `Konto ${created.name} utworzone. Losowe hasło wysłano na ${created.email}; administrator go nie widzi.`
    name.value = ''
    email.value = ''
    rep.value = ''
    formOpen.value = false
    await Promise.all([reload(), reps.reload()])
  } catch (e) {
    formError.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function toggle(a: AdminAccount) {
  notice.value = null
  try {
    if (a.status === 'active') {
      await blockAccount(a.id)
      notice.value = `Zablokowano ${a.name}: sesje unieważnione, pobieranie raportów wyłączone.`
    } else {
      await unblockAccount(a.id)
      notice.value = `Odblokowano ${a.name}.`
    }
    await reload()
  } catch (e) {
    notice.value = errorMessage(e)
  }
}

async function remove(a: AdminAccount) {
  if (
    !window.confirm(
      `Usunąć konto ${a.name}? Dostęp zostanie odebrany, historia zgłoszeń i dziennik pozostaną. Tej operacji nie można cofnąć.`,
    )
  )
    return
  try {
    await deleteAccount(a.id)
    notice.value = `Usunięto konto ${a.name}. Historia zachowana zgodnie z polityką przechowywania.`
    await Promise.all([reload(), reps.reload()])
  } catch (e) {
    notice.value = errorMessage(e)
  }
}
</script>

<template>
  <div>
    <div class="pagehead">
      <div>
        <h1>Pracownicy</h1>
        <p>Konta handlowców i ich przypisanie do handlowców w systemie danych.</p>
      </div>
      <button class="cta" type="button" :aria-expanded="formOpen" aria-controls="new-account" @click="formOpen = !formOpen">
        <Ico name="userPlus" />Dodaj pracownika
      </button>
    </div>
    <p v-if="notice" class="x-alert done" role="status">
      <Ico name="check" /><span>{{ notice }}</span>
    </p>

    <form v-if="formOpen" id="new-account" class="card params" style="margin-bottom: 23px" novalidate @submit.prevent="submit">
      <h3>Nowe konto handlowca</h3>
      <p class="x-muted" style="margin: -14px 0 18px">Konto jest aktywne od razu. Hasło generuje system i wysyła je pracownikowi.</p>
      <div v-if="formError" class="x-alert error" role="alert">
        <Ico name="alert" /><span>{{ formError }}</span>
      </div>
      <div class="x-grid3">
        <div class="field">
          <label for="acc-name">Imię i nazwisko</label>
          <div class="input"><input id="acc-name" v-model="name" autocomplete="off" /></div>
        </div>
        <div class="field">
          <label for="acc-email">Służbowy e-mail</label>
          <div class="input"><Ico name="mail" /><input id="acc-email" v-model="email" type="email" autocomplete="off" /></div>
        </div>
        <div class="field">
          <label for="acc-rep">Handlowiec w systemie danych</label
          ><select id="acc-rep" v-model="rep" class="x-native" style="height: 62px">
            <option value="" disabled>
              {{ reps.loading.value ? 'Ładowanie…' : freeReps.length ? 'Wybierz handlowca' : 'Wszyscy handlowcy mają konta' }}
            </option>
            <option v-for="r in freeReps" :key="r.code" :value="r.code">{{ r.code }}</option>
          </select>
        </div>
      </div>
      <button class="cta" type="submit" :disabled="busy"><Ico name="check" />{{ busy ? 'Tworzenie…' : 'Utwórz konto' }}</button>
    </form>

    <AsyncBlock :loading="loading && !data" :error="error" @retry="reload">
      <ul class="x-list">
        <li v-for="a in data ?? []" :key="a.id" class="card x-row">
          <div class="avatar" aria-hidden="true">{{ initials(a.name) }}</div>
          <div class="x-grow">
            <b>{{ a.name }}</b>
            <span
              >{{ proto.anonymize && !a.shared ? 'adres ukryty w demo publicznym' : a.email }} ·
              {{ a.role === 'admin' ? 'administrator' : a.repCode }}</span
            >
            <small>Ostatnie logowanie: {{ a.lastLoginAt ? formatDateTime(a.lastLoginAt) : 'nigdy' }}</small>
          </div>
          <span class="badge" :class="a.status === 'active' ? 'done' : 'error'">{{
            a.status === 'active' ? 'Aktywne' : 'Zablokowane'
          }}</span>
          <span v-if="a.shared" class="badge neutral">Wspólne konto demo</span>
          <span v-else-if="a.role === 'admin'" class="badge neutral">Administrator</span>
          <template v-else>
            <button class="x-btn2" type="button" @click="toggle(a)">
              <Ico :name="a.status === 'active' ? 'lock' : 'unlock'" />{{ a.status === 'active' ? 'Zablokuj' : 'Odblokuj'
              }}<span class="sr-only"> {{ a.name }}</span>
            </button>
            <button class="x-btn2 danger" type="button" @click="remove(a)">
              <Ico name="trash" />Usuń<span class="sr-only"> {{ a.name }}</span>
            </button>
          </template>
        </li>
      </ul>
    </AsyncBlock>
  </div>
</template>

<style scoped>
.x-grid3 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 0 18px;
}
.avatar {
  display: grid;
  place-items: center;
  width: 45px;
  height: 45px;
  border-radius: 12px;
  background: #ebe5fc;
  color: #7049a6;
  font-weight: 700;
  font-size: 14px;
  flex-shrink: 0;
}
</style>
