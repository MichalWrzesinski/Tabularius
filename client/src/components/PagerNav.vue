<script setup lang="ts">
import { ArrowLeft, ArrowRight } from 'lucide-vue-next'

const props = defineProps<{ page: number; pages: number; shown: number; total: number; noun: string }>()
const emit = defineEmits<{ 'update:page': [number] }>()
function go(p: number) {
  if (p >= 1 && p <= props.pages) emit('update:page', p)
}
</script>

<template>
  <nav class="pager" aria-label="Stronicowanie">
    <span aria-live="polite">Wyświetlono {{ shown }} z {{ total }} {{ noun }}</span>
    <div v-if="pages > 1" class="pager__controls">
      <button class="btn btn--secondary btn--small" type="button" :disabled="page <= 1" @click="go(page - 1)">
        <ArrowLeft aria-hidden="true" />Poprzednia
      </button>
      <span class="pager__state"><span class="sr-only">Strona </span>{{ page }} / {{ pages }}</span>
      <button class="btn btn--small" type="button" :disabled="page >= pages" @click="go(page + 1)">
        Następna<ArrowRight aria-hidden="true" />
      </button>
    </div>
  </nav>
</template>
