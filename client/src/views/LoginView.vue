<script setup lang="ts">
import { ArrowRight, Database, Eye, EyeOff, FileText, LogIn, Mail } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BrandMark from '../components/BrandMark.vue'
import { DEMO_MAILBOX } from '../mock/data'
import { DEMO_CREDENTIALS, login, proto } from '../mock/api'

const props = defineProps<{ role: 'admin' | 'employee' }>()
const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')
const show = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<{ email?: string; password?: string }>({})

const isAdmin = computed(() => props.role === 'admin')
const creds = computed(() => DEMO_CREDENTIALS[props.role])

async function submit() {
  fieldErrors.value = {}
  error.value = null
  if (!email.value.trim()) fieldErrors.value.email = 'Podaj adres e-mail.'
  else if (!/^\S+@\S+\.\S+$/.test(email.value.trim())) fieldErrors.value.email = 'Podaj prawidłowy adres e-mail.'
  if (!password.value) fieldErrors.value.password = 'Podaj hasło.'
  if (fieldErrors.value.email || fieldErrors.value.password) return
  busy.value = true
  try {
    await login(props.role, email.value, password.value)
    const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : null
    await router.push(next ?? (isAdmin.value ? '/admin' : '/moje-raporty'))
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Nie udało się zalogować.'
  } finally {
    busy.value = false
  }
}

function fillDemo() {
  email.value = creds.value.email
  password.value = creds.value.password
}
</script>

<template>
  <div class="login" :class="isAdmin ? 'login--admin' : 'login--emp'">
    <section class="hero">
      <RouterLink to="/" class="hero__brand"><BrandMark :size="52" /></RouterLink>
      <div class="hero__lead">
        <p class="eyebrow">Od wiadomości do raportu</p>
        <h1 v-if="isAdmin">Zgłoszenia i raporty w jednym miejscu</h1>
        <h1 v-else>Od e-maila do gotowego raportu</h1>
        <p class="intro">
          {{ isAdmin ? 'Śledź zgłoszenia, uzupełniaj parametry i zarządzaj kontami handlowców.' : 'Wyślij prośbę e-mailem. Gdy raport będzie gotowy, otrzymasz link do pobrania.' }}
        </p>
        <div class="flow" aria-hidden="true">
          <span class="flowbox"><Mail /></span><span class="flowline" />
          <span class="flowbox"><Database /></span><span class="flowline" />
          <span class="flowbox"><FileText /></span>
        </div>
      </div>
      <div v-if="proto.mode === 'demo'" class="demo">
        <h2>Wypróbuj demo</h2>
        <p>Wyślij e-mail z prośbą na adres:</p>
        <p class="demo__address">{{ DEMO_MAILBOX }}</p>
        <p>Przykład: „Przygotuj raport sprzedaży za październik 2026”. Link do raportu otrzymasz w odpowiedzi.</p>
        <p class="demo__warn">Widoki demo są wspólne dla uczestników. Nie wysyłaj danych poufnych ani prawdziwych danych firmowych.</p>
      </div>
    </section>

    <section class="formarea">
      <p class="toprole"><RouterLink to="/" class="mbrand"><BrandMark :size="38" /></RouterLink><span class="toprole__spacer" /><span v-if="proto.mode === 'demo'" class="pill">DEMO</span>{{ isAdmin ? 'Panel administratora' : 'Panel pracownika' }}</p>
      <form class="form" novalidate @submit.prevent="submit">
        <p class="eyebrow form__eyebrow">{{ isAdmin ? 'Dostęp operacyjny' : 'Twój raport jest blisko' }}</p>
        <h2 class="form__title">{{ isAdmin ? 'Zaloguj się' : 'Odbierz raport' }}</h2>
        <p class="form__sub">{{ isAdmin ? 'Panel do obsługi zgłoszeń i kont.' : 'Zaloguj się, aby przejść do swoich raportów.' }}</p>

        <div v-if="route.query.next && !isAdmin" class="alert alert--info" style="margin-bottom: 22px">
          <LogIn aria-hidden="true" /><p>Po zalogowaniu otworzymy raport z wiadomości e-mail.</p>
        </div>
        <div v-if="error" class="alert alert--error" role="alert" style="margin-bottom: 22px"><p>{{ error }}</p></div>

        <div class="field">
          <label :for="`${role}-email`">E-mail</label>
          <div class="input" :class="{ 'input--invalid': fieldErrors.email }">
            <Mail aria-hidden="true" />
            <input :id="`${role}-email`" v-model="email" type="email" autocomplete="username" placeholder="np. anna.kowalska@firma.pl"
              :aria-invalid="!!fieldErrors.email" :aria-describedby="fieldErrors.email ? `${role}-email-err` : undefined" />
          </div>
          <p v-if="fieldErrors.email" :id="`${role}-email-err`" class="field__error">{{ fieldErrors.email }}</p>
        </div>
        <div class="field">
          <label :for="`${role}-password`">Hasło</label>
          <div class="input" :class="{ 'input--invalid': fieldErrors.password }">
            <input :id="`${role}-password`" v-model="password" :type="show ? 'text' : 'password'" autocomplete="current-password" placeholder="Wprowadź hasło"
              :aria-invalid="!!fieldErrors.password" :aria-describedby="fieldErrors.password ? `${role}-pass-err` : undefined" />
            <button class="icon-btn" type="button" :aria-pressed="show" :aria-label="show ? 'Ukryj hasło' : 'Pokaż hasło'" @click="show = !show">
              <component :is="show ? EyeOff : Eye" aria-hidden="true" />
            </button>
          </div>
          <p v-if="fieldErrors.password" :id="`${role}-pass-err`" class="field__error">{{ fieldErrors.password }}</p>
        </div>
        <p class="forgot"><a href="#" @click.prevent="error = 'Prototyp: reset hasła wygeneruje nowe hasło i wyśle je na zarejestrowany adres (bez informacji, czy konto istnieje).'">Nie pamiętasz hasła?</a></p>

        <div class="form__actions">
          <button class="btn" type="submit" :disabled="busy">
            <LogIn aria-hidden="true" />{{ busy ? 'Logowanie…' : 'Zaloguj się' }}
          </button>
        </div>

        <div v-if="proto.mode === 'demo'" class="creds">
          <p><b>Wspólne konto demonstracyjne</b></p>
          <p>E-mail: <code>{{ creds.email }}</code><br />Hasło: <code>{{ creds.password }}</code></p>
          <button type="button" class="textlink" @click="fillDemo">Wypełnij dane demo<ArrowRight aria-hidden="true" /></button>
        </div>
      </form>
      <p class="formfooter">© 2026 Tabularius</p>
    </section>
  </div>
</template>

<style scoped>
.login {
  display: grid;
  grid-template-columns: minmax(380px, 43%) 1fr;
  min-height: 100vh;
  background: #fff;
}
.login--emp {
  --accent: var(--emp-600);
  --accent-light: var(--emp-500);
  --accent-dark: var(--emp-700);
}
.login--admin {
  --accent: var(--admin-600);
  --accent-light: var(--admin-500);
  --accent-dark: var(--admin-700);
}
.hero {
  position: relative;
  isolation: isolate;
  padding: 52px clamp(24px, 4.5vw, 64px);
  color: #fff;
  display: flex;
  flex-direction: column;
  gap: 34px;
}
.login--admin .hero {
  background:
    url('../assets/pattern-flow.svg') center / 100% 100% no-repeat,
    radial-gradient(ellipse at 100% 40%, #5d2790 0, transparent 65%),
    linear-gradient(145deg, var(--admin-side-1), var(--admin-side-2) 65%, var(--admin-side-3));
}
.login--emp .hero {
  background:
    url('../assets/pattern-flow.svg') center / 100% 100% no-repeat,
    radial-gradient(ellipse at 100% 38%, #118b91 0, transparent 68%),
    linear-gradient(145deg, var(--emp-hero-1), var(--emp-hero-2) 65%, var(--emp-hero-3));
}
.hero__brand {
  color: #fff;
  text-decoration: none;
  align-self: flex-start;
}
.eyebrow {
  font-size: 12px;
  letter-spacing: 2px;
  font-weight: 700;
  text-transform: uppercase;
}
.hero .eyebrow {
  color: #e2d3f2;
}
.login--emp .hero .eyebrow {
  color: #c4e8ea;
}
.hero h1 {
  font-size: clamp(32px, 3.4vw, 49px);
  line-height: 1.13;
  letter-spacing: -1.2px;
  margin: 16px 0 20px;
}
.intro {
  font-size: clamp(15px, 1.4vw, 20px);
  line-height: 1.6;
  max-width: 460px;
  color: #f1eaf7;
}
.flow {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 28px;
}
.flowbox {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--sun);
  color: var(--mark);
  flex-shrink: 0;
}
.flowbox svg {
  width: 28px;
  height: 28px;
}
.flowline {
  height: 2px;
  width: 48px;
  background: #ffffff70;
}
.demo {
  padding: 22px 24px;
  border-radius: 18px;
  border: 1px solid #ffffff30;
  background: #ffffff16;
  font-size: 15px;
  line-height: 1.6;
}
.demo h2 {
  font-size: 21px;
  margin-bottom: 8px;
}
.demo__address {
  font-weight: 700;
  font-size: 19px;
  margin: 4px 0 8px;
  overflow-wrap: anywhere;
}
.demo__warn {
  margin-top: 10px;
  font-size: 13px;
  color: #f6ecff;
}
.formarea {
  padding: 48px clamp(20px, 6vw, 100px) 28px;
  display: flex;
  flex-direction: column;
}
.toprole {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 10px;
  color: var(--muted);
  font-size: 13px;
}
.mbrand {
  display: none;
  color: var(--ink);
  text-decoration: none;
  margin-right: auto;
}
.pill {
  border: 1px solid var(--line);
  border-radius: 50px;
  padding: 6px 12px;
  font-size: 11px;
  letter-spacing: 1px;
  font-weight: 700;
  color: var(--ink-soft);
}
.form {
  width: 100%;
  max-width: 460px;
  margin: clamp(32px, 9vh, 97px) auto 0;
}
.form__eyebrow {
  color: var(--accent);
  margin-bottom: 14px;
}
.form__title {
  font-size: clamp(30px, 3vw, 43px);
  letter-spacing: -1.2px;
  margin-bottom: 10px;
}
.form__sub {
  color: var(--muted);
  font-size: 16px;
  margin-bottom: 32px;
}
.forgot {
  text-align: right;
  margin: -6px 0 26px;
  font-size: 14px;
}
.forgot a {
  color: var(--accent);
  display: inline-block;
  padding: 10px 0;
}
.form__actions {
  display: flex;
  justify-content: center;
}
.creds {
  margin-top: 28px;
  padding: 16px 18px;
  border-radius: 14px;
  background: var(--surface-muted);
  font-size: 14px;
  line-height: 1.7;
}
.login--emp .creds {
  background: var(--emp-100);
}
code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 13px;
}
.formfooter {
  margin-top: auto;
  padding-top: 28px;
  text-align: right;
  font-size: 12px;
  color: var(--muted);
}
@media (max-width: 860px) {
  .login {
    display: flex;
    flex-direction: column-reverse;
  }
  .hero {
    padding: 28px 20px 30px;
  }
  .hero__brand {
    display: none;
  }
  .mbrand {
    display: inline-flex;
  }
  .formarea {
    padding: 20px 20px 10px;
  }
  .form {
    margin-top: 24px;
  }
}
</style>
