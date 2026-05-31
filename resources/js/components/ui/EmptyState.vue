<template>
  <section class="empty-state">
    <h2>{{ title }}</h2>
    <p v-if="message">{{ message }}</p>
  </section>
</template>

<script setup lang="ts">
defineProps<{
  title: string;
  message?: string;
}>();
</script>
