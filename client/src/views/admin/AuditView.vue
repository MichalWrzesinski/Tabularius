<script setup lang="ts">
import AsyncBlock from '../../components/AsyncBlock.vue'
import { formatDateTime } from '../../lib/format'
import { useAsync } from '../../lib/useAsync'
import { listAudit } from '../../mock/api'

const { data, loading, error, reload } = useAsync(listAudit)
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Dziennik zdarzeń</h1><p>Zdarzenia bezpieczeństwa i zmiany wykonane przez ludzi. Bez treści wiadomości i raportów.</p></div></div>
    <AsyncBlock :loading="loading && !data" :error="error" @retry="reload">
      <ol class="timeline card card__body">
        <li v-for="(e, i) in data ?? []" :key="i">
          <time :datetime="e.at">{{ formatDateTime(e.at) }} · {{ e.actor }}</time>
          {{ e.action }} — {{ e.target }}
        </li>
      </ol>
    </AsyncBlock>
  </div>
</template>
