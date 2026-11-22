<script setup lang="ts">
import { computed } from 'vue'

// Plain text only: paragraphs on blank lines, line breaks kept. Never rendered as HTML.
const props = defineProps<{ text: string }>()
const paras = computed(() => props.text.split(/\n{2,}/).map((p) => p.split('\n')))
</script>

<template>
  <div class="mailbody">
    <p v-for="(lines, i) in paras" :key="i">
      <template v-for="(l, j) in lines" :key="j"><br v-if="j" />{{ l }}</template>
    </p>
  </div>
</template>
