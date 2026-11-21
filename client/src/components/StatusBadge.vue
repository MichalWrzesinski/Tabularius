<script setup lang="ts">
import { computed } from 'vue'
import { STATUS } from '../lib/status'
import type { TicketStatus } from '../mock/types'

const props = defineProps<{ status: TicketStatus }>()
const meta = computed(() => STATUS[props.status])
</script>

<template>
  <span class="badge" :class="`badge--${meta.tone}`">{{ meta.label }}</span>
</template>
