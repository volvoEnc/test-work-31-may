<template>
  <nav v-if="meta.total > meta.per_page" class="pagination" aria-label="Pagination">
    <button class="btn btn--secondary" type="button" :disabled="meta.current_page <= 1" @click="emit('change', meta.current_page - 1)">
      Prev
    </button>
    <span class="pagination__status">
      Page {{ meta.current_page }} of {{ lastPage }}
    </span>
    <button class="btn btn--secondary" type="button" :disabled="meta.current_page >= lastPage" @click="emit('change', meta.current_page + 1)">
      Next
    </button>
  </nav>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { PaginationMeta } from '../../types/proxy';

const props = defineProps<{
  meta: PaginationMeta;
}>();

const emit = defineEmits<{
  change: [page: number];
}>();

const lastPage = computed(() => props.meta.last_page ?? Math.max(1, Math.ceil(props.meta.total / props.meta.per_page)));
</script>
