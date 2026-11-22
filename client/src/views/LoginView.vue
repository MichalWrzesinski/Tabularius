<script setup lang="ts">
// Mockups: docs/design/mockups/{desktop,mobile}/01-logowanie-pracownik.html and 02-logowanie-admin.html.
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import logo from '../assets/logo.svg'
import Ico from '../components/Ico.vue'
import { proto } from '../mock/api'
import { errorMessage } from '../lib/http'
import { login, requestPasswordReset } from '../lib/session'
import { DEMO_MAILBOX } from '../mock/data'

const props = defineProps<{ role: 'admin' | 'employee' }>()
const route = useRoute()
const router = useRouter()
const isAdmin = computed(() => props.role === 'admin')
// Public demo credentials (brief §4.1), shown only in demo mode.
const DEMO = {
  employee: { email: 'handlowiec@example.com', password: 'Demo123!' },
  admin: { email: 'admin@example.com', password: 'Demo123!' },
}
const creds = computed(() => DEMO[props.role])
const info = ref<string | null>(null)

const email = ref('')
const password = ref('')
const show = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const emailError = ref<string | null>(null)
const passError = ref<string | null>(null)

async function submit() {
  error.value = null
  emailError.value = !email.value.trim()
    ? 'Podaj adres e-mail.'
    : /^\S+@\S+\.\S+$/.test(email.value.trim())
      ? null
      : 'Podaj prawidłowy adres e-mail.'
  passError.value = password.value ? null : 'Podaj hasło.'
  if (emailError.value || passError.value) return
  busy.value = true
  try {
    await login(props.role, email.value, password.value)
    const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : null
    await router.push(next ?? (isAdmin.value ? '/admin' : '/moje-raporty'))
  } catch (e) {
    const code = (e as { code?: string }).code
    error.value =
      code === 'not_admin'
        ? 'To konto nie ma dostępu do panelu administratora.'
        : code === 'not_rep'
          ? 'To konto administratora — zaloguj się w panelu administratora.'
          : errorMessage(e)
  } finally {
    busy.value = false
  }
}
async function forgot() {
  error.value = null
  info.value = null
  if (!/^\S+@\S+\.\S+$/.test(email.value.trim())) {
    emailError.value = 'Wpisz adres e-mail, na który wyślemy nowe hasło.'
    return
  }
  try {
    await requestPasswordReset(email.value)
    info.value = 'Jeśli konto o tym adresie istnieje, wyślemy na nie nowe hasło.'
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="screen login" :class="{ 'x-emp': !isAdmin }">
    <section class="hero" :class="{ blue: !isAdmin }">
      <RouterLink class="brand" to="/" style="color: inherit"><img :src="logo" alt="" />Tabularius</RouterLink>
      <div class="lead">
        <div class="eyebrow">Od wiadomości do raportu</div>
        <h1 v-if="isAdmin">Zarządzaj<br />zgłoszeniami</h1>
        <h1 v-else>Od e-maila<br />do gotowego raportu</h1>
        <p v-if="isAdmin" class="intro">
          Przeglądaj wiadomości pracowników, <br />sprawdzaj status realizacji i pobieraj <br />gotowe raporty.
        </p>
        <p v-else class="intro">Wyślij prośbę e-mailem. Gdy raport będzie <br />gotowy, otrzymasz link do pobrania.</p>
      </div>
      <div class="flow" aria-hidden="true">
        <div class="flowbox"><Ico name="mail" /></div>
        <div class="flowline" />
        <div class="flowbox"><Ico name="db" /></div>
        <div class="flowline" />
        <div class="flowbox"><Ico name="calendar" /></div>
      </div>
      <div v-if="proto.mode === 'demo'" class="demo">
        <template v-if="isAdmin">
          <h3>Dostęp do demo</h3>
          <p>Przykładowe dane logowania</p>
          <p style="margin-top: 12px">
            E-mail: <b>{{ creds.email }}</b
            ><br />Hasło: <b>{{ creds.password }}</b>
          </p>
          <p style="font-size: 13px; margin-top: 15px; opacity: 0.8">Konto demonstracyjne, wspólne dla uczestników.</p>
        </template>
        <template v-else>
          <h3>Wypróbuj demo</h3>
          <p>Wyślij e-mail z poleceniem na adres:</p>
          <b class="address">{{ DEMO_MAILBOX }}</b>
          <p>Przykład: Przygotuj raport sprzedaży<br />za październik 2026.</p>
          <p style="font-size: 13px; margin-top: 12px; opacity: 0.8">Link do raportu otrzymasz w odpowiedzi.</p>
          <p style="margin-top: 18px">Przykładowe dane logowania</p>
          <p style="margin-top: 12px">
            E-mail: <b>{{ creds.email }}</b
            ><br />Hasło: <b>{{ creds.password }}</b>
          </p>
        </template>
      </div>
      <div class="herofoot">TABULARIUS &nbsp; / &nbsp; WERSJA DEMONSTRACYJNA</div>
    </section>

    <section class="formarea">
      <div class="toprole">
        <span v-if="proto.mode === 'demo'" class="pill">DEMO</span> {{ isAdmin ? 'Panel administratora' : 'Panel pracownika' }}
      </div>
      <form id="main" class="form" novalidate @submit.prevent="submit">
        <div class="eyebrow">{{ isAdmin ? 'Panel administratora' : 'Twój raport jest blisko' }}</div>
        <h2>Odbierz raport</h2>
        <p class="sub">{{ isAdmin ? 'Zaloguj się, aby przejść do panelu.' : 'Zaloguj się, aby przejść do swoich raportów.' }}</p>

        <div v-if="route.query.next && !isAdmin" class="x-alert progress">
          <Ico name="info" /><span>Po zalogowaniu otworzymy raport z wiadomości e-mail.</span>
        </div>
        <div v-if="error" class="x-alert error" role="alert">
          <Ico name="alert" /><span>{{ error }}</span>
        </div>

        <div class="field">
          <label :for="`${role}-email`">E-mail</label>
          <div class="input">
            <Ico name="mail" />
            <input
              :id="`${role}-email`"
              v-model="email"
              type="email"
              autocomplete="username"
              :placeholder="isAdmin ? 'np. admin@firma.pl' : 'np. anna.kowalska@firma.pl'"
              :aria-invalid="!!emailError"
              :aria-describedby="emailError ? `${role}-email-err` : undefined"
            />
          </div>
          <p v-if="emailError" :id="`${role}-email-err`" class="x-fielderr">{{ emailError }}</p>
        </div>
        <div class="field">
          <label :for="`${role}-pass`">Hasło</label>
          <div class="input">
            <input
              :id="`${role}-pass`"
              v-model="password"
              :type="show ? 'text' : 'password'"
              autocomplete="current-password"
              placeholder="Wprowadź hasło"
              :aria-invalid="!!passError"
              :aria-describedby="passError ? `${role}-pass-err` : undefined"
            />
            <button
              class="x-eye"
              type="button"
              :aria-pressed="show"
              :aria-label="show ? 'Ukryj hasło' : 'Pokaż hasło'"
              @click="show = !show"
            >
              <Ico :name="show ? 'eyeOff' : 'eye'" />
            </button>
          </div>
          <p v-if="passError" :id="`${role}-pass-err`" class="x-fielderr">{{ passError }}</p>
        </div>
        <a class="forgot" href="#" @click.prevent="forgot">Nie pamiętasz hasła?</a>
        <button class="cta" :class="{ blue: !isAdmin }" type="submit" :disabled="busy">
          <Ico name="login" :width="1.8" />{{ busy ? 'Logowanie…' : 'Zaloguj się' }}
        </button>
        <p v-if="proto.mode === 'demo'" class="formnote x-desk">
          {{
            isAdmin ? 'Dane dostępowe do demo znajdziesz po lewej stronie.' : 'Instrukcję wypróbowania demo znajdziesz po lewej stronie.'
          }}
        </p>
        <p v-if="proto.mode === 'demo'" class="formnote x-mob">
          {{ isAdmin ? 'Dane dostępowe do demo znajdziesz poniżej.' : 'Instrukcję wypróbowania demo znajdziesz poniżej.' }}
        </p>
      </form>
      <div v-if="proto.mode === 'demo'" class="demo">
        <template v-if="isAdmin">
          <h3>Dostęp do demo</h3>
          <p>Przykładowe dane logowania</p>
          <p style="margin-top: 12px">
            E-mail: <b>{{ creds.email }}</b
            ><br />Hasło: <b>{{ creds.password }}</b>
          </p>
          <p style="font-size: 13px; margin-top: 15px; opacity: 0.8">Konto demonstracyjne, wspólne dla uczestników.</p>
        </template>
        <template v-else>
          <h3>Wypróbuj demo</h3>
          <p>Wyślij e-mail z poleceniem na adres:</p>
          <b class="address">{{ DEMO_MAILBOX }}</b>
          <p>Przykład: Przygotuj raport sprzedaży<br />za październik 2026.</p>
          <p style="font-size: 13px; margin-top: 12px; opacity: 0.8">Link do raportu otrzymasz w odpowiedzi.</p>
          <p style="margin-top: 18px">Przykładowe dane logowania</p>
          <p style="margin-top: 12px">
            E-mail: <b>{{ creds.email }}</b
            ><br />Hasło: <b>{{ creds.password }}</b>
          </p>
        </template>
      </div>
      <div class="formfooter"><span>© 2026 Tabularius</span></div>
    </section>
  </div>
</template>
