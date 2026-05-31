<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" role="presentation" @click.self="emit('close')">
      <form class="modal" role="dialog" aria-modal="true" :aria-labelledby="titleId" @submit.prevent="submit">
        <header class="modal__header">
          <h2 :id="titleId">{{ proxy ? 'Edit proxy' : 'Add proxy' }}</h2>
          <button class="icon-btn" type="button" aria-label="Close" @click="emit('close')">x</button>
        </header>

        <div class="form-grid">
          <label class="field">
            <span>Name</span>
            <input v-model.trim="form.name" type="text" maxlength="120" autocomplete="off" />
            <span v-if="fieldErrors.name?.[0]" class="field-error">{{ fieldErrors.name[0] }}</span>
          </label>

          <label class="field">
            <span>Scheme</span>
            <select v-model="form.scheme" required>
              <option value="http">http</option>
              <option value="https">https</option>
              <option value="socks4">socks4</option>
              <option value="socks5">socks5</option>
            </select>
            <span v-if="fieldErrors.scheme?.[0]" class="field-error">{{ fieldErrors.scheme[0] }}</span>
          </label>

          <label class="field field--wide">
            <span>Host</span>
            <input v-model.trim="form.host" type="text" required maxlength="255" autocomplete="off" />
            <span v-if="fieldErrors.host?.[0]" class="field-error">{{ fieldErrors.host[0] }}</span>
          </label>

          <label class="field">
            <span>Port</span>
            <input v-model.number="form.port" type="number" required min="1" max="65535" inputmode="numeric" />
            <span v-if="fieldErrors.port?.[0]" class="field-error">{{ fieldErrors.port[0] }}</span>
          </label>

          <label class="field">
            <span>Username</span>
            <input v-model.trim="form.username" type="text" maxlength="255" autocomplete="off" />
            <span v-if="fieldErrors.username?.[0]" class="field-error">{{ fieldErrors.username[0] }}</span>
          </label>

          <label class="field field--wide">
            <span>Password</span>
            <input
              v-model="form.password"
              type="password"
              maxlength="2048"
              autocomplete="new-password"
              :placeholder="proxy ? 'Unchanged' : ''"
              :disabled="clearPassword"
            />
            <span v-if="fieldErrors.password?.[0]" class="field-error">{{ fieldErrors.password[0] }}</span>
          </label>
        </div>

        <label v-if="proxy" class="check-row">
          <input v-model="clearPassword" type="checkbox" />
          <span>Clear password</span>
        </label>

        <footer class="modal__actions">
          <button class="btn btn--secondary" type="button" @click="emit('close')">Cancel</button>
          <LoadingButton label="Save" loading-label="Saving" variant="primary" type="submit" :loading="saving" />
        </footer>
      </form>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import LoadingButton from '../ui/LoadingButton.vue';
import type { ProxyPayload, ProxyScheme, ProxyServer } from '../../types/proxy';

const props = withDefaults(
  defineProps<{
    open: boolean;
    proxy?: ProxyServer | null;
    saving?: boolean;
    fieldErrors?: Record<string, string[]>;
  }>(),
  {
    proxy: null,
    saving: false,
    fieldErrors: () => ({}),
  },
);

const emit = defineEmits<{
  close: [];
  submit: [payload: ProxyPayload];
}>();

const form = reactive({
  name: '',
  scheme: 'http' as ProxyScheme,
  host: '',
  port: 8080,
  username: '',
  password: '',
});

const clearPassword = ref(false);
const titleId = `proxy-form-${Math.random().toString(36).slice(2)}`;

watch(
  () => [props.open, props.proxy] as const,
  () => {
    const proxy = props.proxy;
    form.name = proxy?.name ?? '';
    form.scheme = proxy?.scheme ?? 'http';
    form.host = proxy?.host ?? '';
    form.port = proxy?.port ?? 8080;
    form.username = proxy?.username ?? '';
    form.password = '';
    clearPassword.value = false;
  },
  { immediate: true },
);

function submit() {
  const payload: ProxyPayload = {
    name: form.name || null,
    scheme: form.scheme,
    host: form.host,
    port: Number(form.port),
    username: form.username || null,
  };

  if (props.proxy && clearPassword.value) {
    payload.password = null;
  } else if (!props.proxy || form.password !== '') {
    payload.password = form.password || null;
  }

  emit('submit', payload);
}
</script>
