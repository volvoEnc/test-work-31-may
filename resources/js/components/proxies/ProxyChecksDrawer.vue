<template>
  <Teleport to="body">
    <div v-if="open" class="overlay overlay--drawer" role="presentation" @click.self="emit('close')">
      <aside class="drawer" role="dialog" aria-modal="true" :aria-labelledby="titleId">
        <header class="drawer__header">
          <div>
            <h2 :id="titleId">Check history</h2>
            <p v-if="proxy" class="drawer__subtitle truncate">{{ proxy.display_address }}</p>
          </div>
          <button class="icon-btn" type="button" aria-label="Close" @click="emit('close')">x</button>
        </header>

        <div v-if="checks.length === 0" class="drawer__empty">No checks</div>
        <ol v-else class="check-list">
          <li v-for="check in checks" :key="check.id" class="check-item">
            <div class="check-item__top">
              <span class="status-badge" :class="`status-badge--${check.status}`">{{ check.status }}</span>
              <span class="check-item__source">{{ check.source }}</span>
              <time>{{ formatDate(check.finished_at) }}</time>
            </div>
            <div class="check-item__meta">
              <span>{{ formatResponse(check.response_time_ms) }}</span>
              <span>{{ check.http_status ? `HTTP ${check.http_status}` : check.error_code || '-' }}</span>
            </div>
            <p v-if="check.error_message" class="check-item__error">{{ check.error_message }}</p>
          </li>
        </ol>
      </aside>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import type { ProxyCheck, ProxyServer } from '../../types/proxy';

defineProps<{
  open: boolean;
  proxy: ProxyServer | null;
  checks: ProxyCheck[];
}>();

const emit = defineEmits<{
  close: [];
}>();

const titleId = `checks-${Math.random().toString(36).slice(2)}`;

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
