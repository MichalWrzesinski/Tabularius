<script setup lang="ts">
import type { ReportFile } from '../mock/types'

defineProps<{ file: ReportFile }>()
</script>

<template>
  <div class="preview-table" tabindex="0" role="region" :aria-label="`Podgląd pliku ${file.name}`">
    <table>
      <thead>
        <tr><th v-for="h in file.header" :key="h" scope="col">{{ h }}</th></tr>
      </thead>
      <tbody>
        <tr v-for="(r, i) in file.rows" :key="i" :class="{ 'is-meta': r.type === 'meta', 'is-total': r.type === 'total' }">
          <td v-for="(c, j) in r.cells" :key="j" :class="{ num: /^-?[\d ]+,\d{2}$/.test(c) }">{{ c }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
