<script setup lang="ts">
import AsyncBlock from '../../components/AsyncBlock.vue'
import Ico from '../../components/Ico.vue'
import { csvSizeLabel, downloadCsv } from '../../lib/download'
import { formatDateTime } from '../../lib/format'
import { OPERATION_LABEL } from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import { listFiles } from '../../mock/api'

const { data, loading, error, reload } = useAsync(listFiles)
</script>

<template>
  <div>
    <div class="pagehead">
      <div>
        <h1>Pliki</h1>
        <p>Wygenerowane raporty. Po terminie ważności pliki są usuwane.</p>
      </div>
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
              >{{ csvSizeLabel(f) }} · zgłoszenie <RouterLink :to="`/admin/zgloszenia/${f.ticketId}`">#{{ f.ticketId }}</RouterLink> · ważny
              do {{ formatDateTime(f.expiresAt) }}</small
            >
          </div>
          <button class="cta" type="button" @click="downloadCsv(f)">
            <Ico name="download" /> Pobierz CSV<span class="sr-only"> {{ f.name }}</span>
          </button>
        </li>
      </ul>
    </AsyncBlock>
  </div>
</template>
