<script setup lang="ts">
import { RefreshCw, TriangleAlert } from 'lucide-vue-next'

defineProps<{ loading: boolean; error: string | null; skeletonRows?: number }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <div v-if="loading" aria-busy="true" aria-live="polite">
    <span class="sr-only">Ładowanie…</span>
    <div v-for="i in skeletonRows ?? 4" :key="i" class="skeleton" style="height: 52px; margin-bottom: 10px" />
  </div>
  <div v-else-if="error" class="alert alert--error" role="alert">
    <TriangleAlert aria-hidden="true" />
    <div>
      <p>{{ error }}</p>
      <button class="btn btn--small btn--secondary" style="margin-top: 10px" type="button" @click="$emit('retry')">
        <RefreshCw aria-hidden="true" />Spróbuj ponownie
      </button>
    </div>
  </div>
  <slot v-else />
</template>
