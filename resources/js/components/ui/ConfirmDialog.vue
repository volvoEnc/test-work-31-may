<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" role="presentation" @click.self="emit('cancel')">
      <section class="dialog" role="dialog" aria-modal="true" :aria-labelledby="titleId">
        <header class="dialog__header">
          <h2 :id="titleId">{{ title }}</h2>
        </header>
        <p class="dialog__message">{{ message }}</p>
        <footer class="dialog__actions">
          <button class="btn btn--secondary" type="button" @click="emit('cancel')">Cancel</button>
          <LoadingButton
            :label="confirmLabel"
            loading-label="Deleting"
            variant="danger"
            :loading="loading"
            @click="emit('confirm')"
          />
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import LoadingButton from './LoadingButton.vue';

withDefaults(
  defineProps<{
    open: boolean;
    title: string;
    message: string;
    confirmLabel?: string;
    loading?: boolean;
  }>(),
  {
    confirmLabel: 'Delete',
    loading: false,
  },
);

const emit = defineEmits<{
  cancel: [];
  confirm: [];
}>();

const titleId = `confirm-${Math.random().toString(36).slice(2)}`;
</script>
