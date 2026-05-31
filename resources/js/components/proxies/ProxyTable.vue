<template>
  <div class="table-wrap">
    <table class="proxy-table">
      <thead>
        <tr>
          <th>Status</th>
          <th>Name</th>
          <th>Proxy</th>
          <th>Response</th>
          <th>Last checked</th>
          <th>Error</th>
          <th class="proxy-table__actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="loading">
          <td colspan="7" class="table-muted">Loading proxies...</td>
        </tr>
        <tr v-else-if="items.length === 0">
          <td colspan="7" class="table-muted">No proxies</td>
        </tr>
        <tr v-for="proxy in items" v-else :key="proxy.id">
          <td><ProxyStatusBadge :status="proxy.status" /></td>
          <td class="cell-strong truncate">{{ proxy.name || '-' }}</td>
          <td>
            <div class="address truncate" :title="proxy.display_address">{{ proxy.display_address }}</div>
            <div v-if="proxy.has_credentials" class="cell-subtle">credentials</div>
          </td>
          <td>{{ formatResponse(proxy.response_time_ms) }}</td>
          <td>{{ formatDate(proxy.last_checked_at) }}</td>
          <td class="cell-error">
            <span class="truncate" :title="proxy.failure_reason || ''">{{ proxy.failure_reason || '-' }}</span>
          </td>
          <td>
            <ProxyActions
              :checking="checkingIds.has(proxy.id)"
              @check="emit('check', proxy)"
              @history="emit('history', proxy)"
              @edit="emit('edit', proxy)"
              @delete="emit('delete', proxy)"
            />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import ProxyActions from './ProxyActions.vue';
import ProxyStatusBadge from './ProxyStatusBadge.vue';
import type { ProxyServer } from '../../types/proxy';

defineProps<{
  items: ProxyServer[];
  loading: boolean;
  checkingIds: Set<number>;
}>();

const emit = defineEmits<{
  check: [proxy: ProxyServer];
  history: [proxy: ProxyServer];
  edit: [proxy: ProxyServer];
  delete: [proxy: ProxyServer];
}>();

function formatDate(value: string | null): string {
  if (!value) {
    return '-';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return '-';
  }

  return new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

function formatResponse(value: number | null): string {
  return value === null ? '-' : `${value} ms`;
}
</script>
