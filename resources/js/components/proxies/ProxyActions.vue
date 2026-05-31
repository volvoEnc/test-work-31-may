<template>
  <div class="table-actions" aria-label="Proxy actions">
    <button class="icon-btn" type="button" :disabled="checking" title="Check" aria-label="Check proxy" @click="emit('check')">
      <span aria-hidden="true">*</span>
    </button>
    <button class="icon-btn" type="button" title="History" aria-label="Check history" @click="emit('history')">
      <span aria-hidden="true">#</span>
    </button>
    <button class="icon-btn" type="button" title="Edit" aria-label="Edit proxy" @click="emit('edit')">
      <span aria-hidden="true">/</span>
    </button>
    <button class="icon-btn icon-btn--danger" type="button" title="Delete" aria-label="Delete proxy" @click="emit('delete')">
      <span aria-hidden="true">×</span>
    </button>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  checking: boolean;
}>();

const emit = defineEmits<{
  check: [];
  history: [];
  edit: [];
  delete: [];
}>();
</script>
