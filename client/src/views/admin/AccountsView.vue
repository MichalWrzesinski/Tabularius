<script setup lang="ts">
import { Lock, LockOpen, Trash2, UserPlus } from 'lucide-vue-next'
import { ref } from 'vue'
import AsyncBlock from '../../components/AsyncBlock.vue'
import { formatDateTime } from '../../lib/format'
import { useAsync } from '../../lib/useAsync'
import { createAccount, deleteAccount, listAccounts, proto, setAccountStatus } from '../../mock/api'
import type { Account } from '../../mock/types'

const { data, loading, error, reload } = useAsync(listAccounts)
const formOpen = ref(false)
const name = ref('')
const email = ref('')
const rep = ref('PH-05')
const formError = ref<string | null>(null)
const notice = ref<string | null>(null)
const busy = ref(false)

async function submit() {
  formError.value = null
  if (!name.value.trim() || !/^\S+@\S+\.\S+$/.test(email.value.trim())) {
    formError.value = 'Podaj imię i nazwisko oraz prawidłowy służbowy adres e-mail.'
    return
  }
  busy.value = true
  try {
    await createAccount(name.value.trim(), email.value.trim(), rep.value)
    notice.value = proto.mode === 'demo'
      ? `Konto utworzone. W demo wiadomość z hasłem została przechwycona i nie trafiła do adresata.`
      : `Konto utworzone. Losowe hasło wysłano na ${email.value.trim()}; administrator go nie widzi.`
    name.value = ''
    email.value = ''
    formOpen.value = false
  } catch (e) {
    formError.value = e instanceof Error ? e.message : 'Nie udało się utworzyć konta.'
  } finally {
    busy.value = false
  }
}

async function toggle(a: Account) {
  notice.value = null
  try {
    await setAccountStatus(a.id, a.status === 'active' ? 'blocked' : 'active')
    notice.value = a.status === 'blocked' ? `Zablokowano ${a.name}: sesje unieważnione, pobieranie raportów wyłączone.` : `Odblokowano ${a.name}.`
  } catch (e) {
    notice.value = e instanceof Error ? e.message : 'Operacja nie powiodła się.'
  }
}

async function remove(a: Account) {
  if (!window.confirm(`Usunąć konto ${a.name}? Dostęp zostanie odebrany, historia zgłoszeń i dziennik pozostaną. Tej operacji nie można cofnąć.`)) return
  try {
    await deleteAccount(a.id)
    notice.value = `Usunięto konto ${a.repCode}. Historia zachowana zgodnie z polityką przechowywania.`
  } catch (e) {
    notice.value = e instanceof Error ? e.message : 'Operacja nie powiodła się.'
  }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div><h1>Pracownicy</h1><p>Konta handlowców i ich przypisanie do handlowców w systemie danych.</p></div>
      <button class="btn" type="button" :aria-expanded="formOpen" aria-controls="new-account" @click="formOpen = !formOpen"><UserPlus aria-hidden="true" />Dodaj pracownika</button>
    </div>

    <p v-if="notice" class="alert alert--success notice" role="status">{{ notice }}</p>

    <form v-if="formOpen" id="new-account" class="card card__body newacc" novalidate @submit.prevent="submit">
      <h2>Nowe konto handlowca</h2>
      <p class="muted">Konto jest aktywne od razu. Hasło generuje system i wysyła je pracownikowi.</p>
      <div v-if="formError" class="alert alert--error" role="alert"><p>{{ formError }}</p></div>
      <div class="newacc__grid">
        <div class="field"><label for="acc-name">Imię i nazwisko</label><div class="input"><input id="acc-name" v-model="name" autocomplete="off" /></div></div>
        <div class="field"><label for="acc-email">Służbowy e-mail</label><div class="input"><input id="acc-email" v-model="email" type="email" autocomplete="off" /></div></div>
        <div class="field">
          <label for="acc-rep">Handlowiec w systemie danych</label>
          <select id="acc-rep" v-model="rep" class="select"><option value="PH-05">PH-05 · Region Wielkopolska (4 klientów)</option><option value="PH-02">PH-02 · Region Mazowsze (9 klientów)</option></select>
        </div>
      </div>
      <button class="btn" type="submit" :disabled="busy">{{ busy ? 'Tworzenie…' : 'Utwórz konto' }}</button>
    </form>

    <AsyncBlock :loading="loading && !data" :error="error" @retry="reload">
      <ul class="accs">
        <li v-for="a in data ?? []" :key="a.id" class="card acc">
          <div class="acc__main">
            <b>{{ a.name }}</b>
            <span>{{ proto.mode === 'demo' && !a.shared ? 'adres fikcyjny' : a.email }} · {{ a.repCode }} · klientów: {{ a.customers }}</span>
            <small>Ostatnie logowanie: {{ a.lastLogin ? formatDateTime(a.lastLogin) : 'nigdy' }}</small>
          </div>
          <span class="badge" :class="a.status === 'active' ? 'badge--done' : 'badge--error'">{{ a.status === 'active' ? 'Aktywne' : 'Zablokowane' }}</span>
          <span v-if="a.shared" class="badge badge--neutral">Wspólne konto demo</span>
          <div v-else class="acc__actions">
            <button class="btn btn--small btn--secondary" type="button" @click="toggle(a)">
              <component :is="a.status === 'active' ? Lock : LockOpen" aria-hidden="true" />{{ a.status === 'active' ? 'Zablokuj' : 'Odblokuj' }}<span class="sr-only"> {{ a.name }}</span>
            </button>
            <button class="btn btn--small btn--secondary danger" type="button" @click="remove(a)"><Trash2 aria-hidden="true" />Usuń<span class="sr-only"> {{ a.name }}</span></button>
          </div>
        </li>
      </ul>
    </AsyncBlock>
  </div>
</template>

<style scoped>
.notice {
  margin-bottom: 16px;
}
.newacc {
  margin-bottom: 20px;
}
.newacc h2 {
  font-size: 19px;
}
.newacc .muted {
  color: var(--muted);
  font-size: 14px;
  margin: 6px 0 18px;
}
.newacc__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 0 18px;
}
.newacc__grid .select {
  min-height: 58px;
}
.accs {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;
}
.acc {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px 16px;
  padding: 18px 22px;
}
.acc__main {
  flex: 1;
  min-width: 220px;
  display: grid;
  gap: 3px;
}
.acc__main span {
  font-size: 14px;
  color: var(--ink-soft);
  overflow-wrap: anywhere;
}
.acc__main small {
  font-size: 12px;
  color: var(--muted);
}
.acc__actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.danger {
  color: var(--st-error-fg);
}
</style>
