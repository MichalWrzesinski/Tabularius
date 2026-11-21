<script setup lang="ts">
import { SearchX } from 'lucide-vue-next'
</script>

<template>
  <main id="main" class="nf">
    <div class="empty">
      <span class="sym"><SearchX aria-hidden="true" /></span>
      <h1>Nie znaleziono strony</h1>
      <p>Sprawdź adres albo wróć na <RouterLink to="/">stronę główną</RouterLink>.</p>
    </div>
  </main>
</template>

<style scoped>
.nf {
  min-height: 100vh;
  display: grid;
  place-items: center;
}
h1 {
  font-size: 22px;
  margin-bottom: 6px;
}
</style>
