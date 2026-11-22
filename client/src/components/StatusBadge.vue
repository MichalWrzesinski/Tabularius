<script setup lang="ts">
import { computed } from 'vue'
import { STATUS, statusLabel } from '../lib/status'
import type { TicketStatus } from '../lib/types'

const props = defineProps<{ status: TicketStatus; reason?: string | null }>()
const tone = computed(() => STATUS[props.status].tone)
</script>

<template>
  <span class="badge" :class="tone">{{ statusLabel(status, reason) }}</span>
</template>
