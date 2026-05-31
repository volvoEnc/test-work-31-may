<template>
  <span class="status-badge" :class="`status-badge--${status}`">
    {{ status }}
  </span>
</template>

<script setup lang="ts">
import type { ProxyStatus } from '../../types/proxy';

defineProps<{
  status: ProxyStatus;
}>();
</script>
