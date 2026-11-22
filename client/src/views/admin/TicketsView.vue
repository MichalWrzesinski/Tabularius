<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AsyncBlock from '../../components/AsyncBlock.vue'
import TicketList from '../../components/TicketList.vue'
import { STATUS } from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import { listTickets } from '../../lib/tickets'
import type { TicketStatus } from '../../lib/types'
import Ico from '../../components/Ico.vue'

const route = useRoute()
const { data, loading, error, reload } = useAsync(listTickets)
const initial = computed(() => {
  const s = route.query.status
  return typeof s === 'string' && s in STATUS ? (s as TicketStatus) : ''
})
</script>

<template>
  <div>
    <div class="pagehead">
      <div>
        <h1>Zgłoszenia</h1>
        <p>Wszystkie prośby odebrane ze skrzynki, od najnowszych.</p>
      </div>
      <RouterLink class="cta" to="/admin/zgloszenia/nowe"><Ico name="mail" /> Nowe zgłoszenie</RouterLink>
    </div>
    <AsyncBlock :loading="loading && !data" :error="error" @retry="reload">
      <TicketList v-if="data" :tickets="data" :per-page="10" :initial-status="initial" />
    </AsyncBlock>
  </div>
</template>
