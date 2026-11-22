<script setup lang="ts">
import { computed } from 'vue'
import AsyncBlock from '../../components/AsyncBlock.vue'
import { actionLabel, listAccounts, listAudit, refLabel } from '../../lib/accounts'
import { formatDateTime } from '../../lib/format'
import { useAsync } from '../../lib/useAsync'

const { data, loading, error, reload } = useAsync(listAudit)
const accounts = useAsync(listAccounts)
const names = computed(() => new Map((accounts.data.value ?? []).map((a) => [a.id, a.name])))
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
      <div v-if="data && !data.length" class="card x-empty">
        <h3>Brak zdarzeń</h3>
        <p>Zdarzenia pojawią się po pierwszych logowaniach i zmianach kont.</p>
      </div>
      <div v-else class="card x-card-pad">
        <ol class="x-timeline">
          <li v-for="(e, i) in data ?? []" :key="i">
            <time :datetime="e.at">{{ formatDateTime(e.at) }} · {{ refLabel(e.actorRef, names) }}</time
            >{{ actionLabel(e.action) }} — {{ refLabel(e.targetRef, names) }}
          </li>
        </ol>
      </div>
    </AsyncBlock>
  </div>
</template>
