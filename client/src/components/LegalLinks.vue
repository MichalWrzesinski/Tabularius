<script setup lang="ts">
// Footer links to the legal pages (TASK-016): stable URLs, open without login.
import { LEGAL_DOCUMENTS } from '../lib/legal'
</script>

<template>
  <nav class="x-legal-links" aria-label="Informacje prawne">
    <RouterLink v-for="doc in LEGAL_DOCUMENTS" :key="doc.path" class="legal-link" :to="doc.path">{{
      doc.path === '/regulamin' ? 'Regulamin' : doc.title
    }}</RouterLink>
  </nav>
</template>
