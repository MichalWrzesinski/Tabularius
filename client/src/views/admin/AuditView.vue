<script setup lang="ts">
import AsyncBlock from '../../components/AsyncBlock.vue'
import { formatDateTime } from '../../lib/format'
import { useAsync } from '../../lib/useAsync'
import { listAudit } from '../../mock/api'

const { data, loading, error, reload } = useAsync(listAudit)
</script>

<template>
  <div>
    <div class="pagehead">
      <div>
        <h1>Dziennik zdarzeń</h1>
        <p>Zdarzenia bezpieczeństwa i zmiany wykonane przez ludzi — bez treści wiadomości i raportów.</p>
      </div>
    </div>
    <AsyncBlock :loading="loading && !data" :error="error" @retry="reload">
      <div class="card x-card-pad">
        <ol class="x-timeline">
          <li v-for="(e, i) in data ?? []" :key="i">
            <time :datetime="e.at">{{ formatDateTime(e.at) }} · {{ e.actor }}</time
            >{{ e.action }} — {{ e.target }}
          </li>
        </ol>
      </div>
    </AsyncBlock>
  </div>
</template>
