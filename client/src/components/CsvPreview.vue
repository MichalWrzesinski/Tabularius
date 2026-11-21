<script setup lang="ts">
import type { ReportFile } from '../mock/types'

defineProps<{ file: ReportFile }>()
const isMoney = (c: string) => /^-?\d+,\d{2}$/.test(c)
</script>

<template>
  <div class="x-preview" tabindex="0" role="region" :aria-label="`Podgląd pliku ${file.name}`">
    <table>
      <thead>
        <tr>
          <th v-for="h in file.header" :key="h" scope="col">{{ h }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(r, i) in file.rows" :key="i" :class="r.type">
          <td v-for="(c, j) in r.cells" :key="j" :class="{ 'x-num': isMoney(c) }">{{ c }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
