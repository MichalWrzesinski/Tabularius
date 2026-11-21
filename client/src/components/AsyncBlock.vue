<script setup lang="ts">
import Ico from './Ico.vue'

defineProps<{ loading: boolean; error: string | null; skeletonRows?: number }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <div v-if="loading" aria-busy="true">
    <span class="sr-only" role="status">Ładowanie…</span>
    <div v-for="i in skeletonRows ?? 4" :key="i" class="x-skel" style="height: 60px" />
  </div>
  <div v-else-if="error" class="x-alert error" role="alert">
    <Ico name="alert" />
    <div>
      <p>{{ error }}</p>
      <button class="x-btn2 x-small" style="margin-top: 12px" type="button" @click="$emit('retry')"><Ico name="refresh" />Spróbuj ponownie</button>
    </div>
  </div>
  <slot v-else />
</template>
