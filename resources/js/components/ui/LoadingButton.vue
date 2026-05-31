<template>
  <button class="btn" :class="variantClass" :disabled="disabled || loading" :type="type">
    <span v-if="loading" class="spinner" aria-hidden="true"></span>
    <span>{{ loading ? loadingLabel : label }}</span>
  </button>
</template>

<script setup lang="ts">
const props = withDefaults(
  defineProps<{
    label: string;
    loading?: boolean;
    loadingLabel?: string;
    disabled?: boolean;
    type?: 'button' | 'submit' | 'reset';
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  }>(),
  {
    loading: false,
    loadingLabel: 'Working',
    disabled: false,
    type: 'button',
    variant: 'secondary',
  },
);

const variantClass = `btn--${props.variant}`;
</script>
