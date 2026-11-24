<script setup lang="ts">
// Icons copied from the owner's mockups' inline SVGs (docs/design/README.md); extra ones drawn in the same 24px line style.
import { computed } from 'vue'

type El =
  | ['path', string]
  | ['circle', number, number, number]
  | ['rect', number, number, number, number, number]
  | ['ellipse', number, number, number, number]

const ICONS: Record<string, El[]> = {
  home: [['path', 'm3 10 9-7 9 7v10H6V10m4 10v-7h4v7']],
  mail: [
    ['rect', 3, 5, 18, 14, 3],
    ['path', 'm4 7 8 6 8-6'],
  ],
  folder: [['path', 'M3 7h7l2 2h9v11H3zM3 7V4h7l2 3']],
  db: [
    ['ellipse', 12, 5, 8, 3],
    ['path', 'M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0'],
  ],
  users: [
    ['circle', 10, 7, 4],
    ['path', 'M2 21v-3a8 8 0 0 1 16 0v3m0-18a4 4 0 0 1 0 8m3 10v-4a5 5 0 0 0-3-4'],
  ],
  calendar: [
    ['rect', 4, 4, 16, 18, 2],
    ['path', 'M8 2v4m8-4v4M8 11h8m-8 5h5'],
  ],
  settings: [
    ['circle', 12, 12, 8],
    ['circle', 12, 12, 3],
  ],
  chevron: [['path', 'm6 9 6 6 6-6']],
  arrow: [['path', 'M4 12h16m-6-6 6 6-6 6']],
  arrowLeft: [['path', 'M20 12H4m6-6-6 6 6 6']],
  search: [
    ['circle', 10, 10, 6],
    ['path', 'm15 15 6 6'],
  ],
  clock: [
    ['circle', 12, 12, 9],
    ['path', 'M12 6v6l4 2'],
  ],
  alert: [
    ['circle', 12, 12, 9],
    ['path', 'M12 6v7m0 3v2'],
  ],
  check: [['path', 'm5 12 5 5 9-10']],
  file: [['path', 'M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9zM14 3v6h6M8 13h8m-8 4h8']],
  download: [['path', 'M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5']],
  login: [['path', 'M14 4h5v16h-5M3 12h12m-4-4 4 4-4 4']],
  logout: [['path', 'M10 4H5v16h5M21 12H9m4-4-4 4 4 4']],
  eye: [
    ['path', 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12'],
    ['circle', 12, 12, 3],
  ],
  eyeOff: [
    ['path', 'M2 12s4-7 10-7c2 0 3.8.7 5.3 1.7M22 12s-4 7-10 7c-2 0-3.8-.7-5.3-1.7M4 4l16 16'],
    ['circle', 12, 12, 3],
  ],
  grid: [
    ['rect', 3, 3, 7, 7, 2],
    ['rect', 14, 3, 7, 7, 2],
    ['rect', 3, 14, 7, 7, 2],
    ['rect', 14, 14, 7, 7, 2],
  ],
  menu: [['path', 'M4 6h16M4 12h16M4 18h16']],
  close: [['path', 'M6 6l12 12M18 6 6 18']],
  refresh: [['path', 'M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7']],
  send: [
    ['path', 'M21 3 3 10l7 3 3 7z'],
    ['path', 'm10 13 11-10'],
  ],
  ban: [
    ['circle', 12, 12, 9],
    ['path', 'M6 6l12 12'],
  ],
  info: [
    ['circle', 12, 12, 9],
    ['path', 'M12 11v6m0-10v1'],
  ],
  lock: [
    ['rect', 5, 11, 14, 10, 2],
    ['path', 'M8 11V7a4 4 0 0 1 8 0v4'],
  ],
  unlock: [
    ['rect', 5, 11, 14, 10, 2],
    ['path', 'M8 11V7a4 4 0 0 1 7.5-2'],
  ],
  trash: [['path', 'M4 7h16M10 11v6m4-6v6M6 7l1 13h10l1-13M9 7V4h6v3']],
  userPlus: [
    ['circle', 9, 7, 4],
    ['path', 'M2 21v-3a7 7 0 0 1 11-5.7M19 14v6m-3-3h6'],
  ],
  shield: [
    ['path', 'M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z'],
    ['path', 'm9 12 2 2 4-4'],
  ],
  flask: [['path', 'M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3']],
  bell: [['path', 'M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 21h4']],
}

const props = defineProps<{ name: keyof typeof ICONS | string; width?: number }>()
const els = computed(() => ICONS[props.name] ?? [])
</script>

<template>
  <svg
    class="ico"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    :stroke-width="width ?? 1.7"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <template v-for="(e, i) in els" :key="i">
      <path v-if="e[0] === 'path'" :d="e[1]" />
      <circle v-else-if="e[0] === 'circle'" :cx="e[1]" :cy="e[2]" :r="e[3]" />
      <rect v-else-if="e[0] === 'rect'" :x="e[1]" :y="e[2]" :width="e[3]" :height="e[4]" :rx="e[5]" />
      <ellipse v-else-if="e[0] === 'ellipse'" :cx="e[1]" :cy="e[2]" :rx="e[3]" :ry="e[4]" />
    </template>
  </svg>
</template>
