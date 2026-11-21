<script setup lang="ts">
import { Download, FolderOpen } from 'lucide-vue-next'
import AsyncBlock from '../../components/AsyncBlock.vue'
import { downloadCsv } from '../../lib/download'
import { formatDateTime } from '../../lib/format'
import { OPERATION_LABEL } from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import { listFiles } from '../../mock/api'

const { data, loading, error, reload } = useAsync(listFiles)
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Pliki</h1><p>Wygenerowane raporty. Pliki po terminie ważności są usuwane.</p></div></div>
    <AsyncBlock :loading="loading && !data" :error="error" @retry="reload">
      <div v-if="data && !data.length" class="card empty">
        <span class="sym"><FolderOpen aria-hidden="true" /></span><h3>Brak plików</h3><p>Raporty pojawią się tu po ukończeniu zgłoszeń.</p>
      </div>
      <ul v-else class="files">
        <li v-for="f in data ?? []" :key="f.id" class="card fileitem">
          <div class="fileitem__main">
            <b>{{ f.name }}</b>
            <span>{{ OPERATION_LABEL[f.operation] }} · {{ f.periodLabel }} · {{ f.scopeLabel }}</span>
            <small>Zgłoszenie <RouterLink :to="`/admin/zgloszenia/${f.ticketId}`">#{{ f.ticketId }}</RouterLink> · utworzono {{ formatDateTime(f.createdAt) }} · ważny do {{ formatDateTime(f.expiresAt) }}</small>
          </div>
          <button class="btn btn--small btn--secondary" type="button" :aria-label="`Pobierz ${f.name}`" @click="downloadCsv(f)"><Download aria-hidden="true" />Pobierz</button>
        </li>
      </ul>
    </AsyncBlock>
  </div>
</template>

<style scoped>
.files {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;
}
.fileitem {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 18px 22px;
  flex-wrap: wrap;
}
.fileitem__main {
  flex: 1;
  min-width: 220px;
  display: grid;
  gap: 4px;
}
.fileitem__main b {
  overflow-wrap: anywhere;
}
.fileitem__main span {
  font-size: 14px;
  color: var(--ink-soft);
}
.fileitem__main small {
  font-size: 12px;
  color: var(--muted);
}
.fileitem__main a {
  color: var(--admin-600);
}
</style>
