<script setup lang="ts">
// Legal pages of the public demo (TASK-016). No mockup: the home page's header, footer and typography. Drafts until the
// service provider reviews them; placeholders are highlighted.
import { computed } from 'vue'
import logo from '../assets/logo.svg'
import LegalLinks from '../components/LegalLinks.vue'
import LegalText from '../components/LegalText.vue'
import { PRIVACY, TERMS } from '../lib/legal'

const props = defineProps<{ doc: 'privacy' | 'terms' }>()
const document = computed(() => (props.doc === 'privacy' ? PRIVACY : TERMS))
</script>

<template>
  <div class="home x-legal">
    <header class="home-header">
      <RouterLink class="brand" to="/" aria-label="Tabularius — strona główna"><img :src="logo" alt="" />Tabularius</RouterLink>
      <span>OD WIADOMOŚCI DO RAPORTU</span>
    </header>
    <main id="main">
      <article class="x-legal-doc" :aria-labelledby="`${props.doc}-title`">
        <h1 :id="`${props.doc}-title`">{{ document.title }}</h1>
        <p class="x-legal-version">Wersja z {{ document.version }}</p>
        <p class="x-legal-draft" role="note">
          Wersja robocza, przed zatwierdzeniem przez usługodawcę. Fragmenty w nawiasach kwadratowych zostaną uzupełnione.
        </p>
        <section v-for="(section, i) in document.sections" :key="section.heading" :aria-labelledby="`${props.doc}-${i}`">
          <h2 :id="`${props.doc}-${i}`">{{ section.heading }}</h2>
          <ul v-if="section.items">
            <li v-for="item in section.items" :key="item"><LegalText :text="item" /></li>
          </ul>
          <p v-for="paragraph in section.paragraphs ?? []" :key="paragraph"><LegalText :text="paragraph" /></p>
        </section>
      </article>
    </main>
    <footer class="home-footer">
      <span>© 2026 Tabularius. Wszelkie prawa zastrzeżone.</span>
      <LegalLinks />
    </footer>
  </div>
</template>
