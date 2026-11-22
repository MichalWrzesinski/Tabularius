<script setup lang="ts">
import AsyncBlock from '../../components/AsyncBlock.vue'
import Ico from '../../components/Ico.vue'
import { formatDateTime } from '../../lib/format'
import { OPERATION_LABEL } from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import { ref } from 'vue'
import { errorMessage } from '../../lib/http'
import { downloadFile, listFiles, sizeLabel } from '../../lib/tickets'
import type { FileMeta } from '../../lib/types'

const { data, loading, error, reload } = useAsync(listFiles)
const downloadError = ref<string | null>(null)
async function download(f: FileMeta) {
  downloadError.value = null
  try {
    await downloadFile(f)
  } catch (e) {
    downloadError.value = errorMessage(e)
  }
}
</script>

<template>
  <div>
    <div class="pagehead">
      <div>
        <h1>Pliki</h1>
        <p>Wygenerowane raporty. Po terminie ważności pliki są usuwane.</p>
      </div>
    </div>
    <div v-if="downloadError" class="x-alert error" role="alert">
      <Ico name="alert" /><span>{{ downloadError }}</span>
    </div>
    <AsyncBlock :loading="loading && !data" :error="error" @retry="reload">
      <div v-if="data && !data.length" class="card x-empty">
        <span class="sym"><Ico name="folder" /></span>
        <h3>Brak plików</h3>
        <p>Raporty pojawią się tu po ukończeniu zgłoszeń.</p>
      </div>
      <ul v-else class="x-list">
        <li v-for="f in data ?? []" :key="f.id" class="card x-row">
          <div class="fileicon"><Ico name="file" :width="2" /></div>
          <div class="x-grow">
            <b>{{ f.name }}</b>
            <span>{{ OPERATION_LABEL[f.operation] }} · {{ f.periodLabel }} · {{ f.scopeLabel }}</span>
            <small
              >{{ sizeLabel(f) }} · {{ f.ownerName }} · zgłoszenie
              <RouterLink :to="`/admin/zgloszenia/${f.ticketId}`">#{{ f.ticketNumber }}</RouterLink> · ważny do
              {{ formatDateTime(f.expiresAt) }}</small
            >
          </div>
          <button class="cta" type="button" @click="download(f)">
            <Ico name="download" /> Pobierz CSV<span class="sr-only"> {{ f.name }}</span>
          </button>
        </li>
      </ul>
    </AsyncBlock>
  </div>
</template>
