<template>
  <main class="app-shell">
    <header class="page-header">
      <div>
        <h1>Proxy Manager</h1>
        <p class="page-header__meta">{{ meta.total }} total</p>
      </div>
      <div class="page-header__actions">
        <LoadingButton label="Refresh all" loading-label="Queueing" variant="secondary" :loading="saving" @click="checkAll" />
        <button class="btn btn--primary" type="button" @click="openCreate">Add proxy</button>
      </div>
    </header>

    <section class="filter-bar" aria-label="Proxy filters">
      <label class="filter-field filter-field--search">
        <span>Search</span>
        <input v-model.trim="filters.search" type="search" placeholder="Name, host, username" />
      </label>
      <label class="filter-field">
        <span>Status</span>
        <select v-model="filters.status">
          <option value="">All</option>
          <option value="unknown">unknown</option>
          <option value="checking">checking</option>
          <option value="online">online</option>
          <option value="offline">offline</option>
        </select>
      </label>
      <label class="filter-field">
        <span>Scheme</span>
        <select v-model="filters.scheme">
          <option value="">All</option>
          <option value="http">http</option>
          <option value="https">https</option>
          <option value="socks4">socks4</option>
          <option value="socks5">socks5</option>
        </select>
      </label>
      <label class="filter-field">
        <span>Sort</span>
        <select v-model="filters.sort">
          <option value="created_at">created</option>
          <option value="last_checked_at">last checked</option>
          <option value="status">status</option>
          <option value="host">host</option>
        </select>
      </label>
      <label class="filter-field">
        <span>Direction</span>
        <select v-model="filters.direction">
          <option value="desc">desc</option>
          <option value="asc">asc</option>
        </select>
      </label>
    </section>

    <div v-if="error" class="alert" role="alert">{{ error }}</div>

    <EmptyState v-if="!loading && items.length === 0" title="No proxies" message="Add a proxy to start checks." />

    <ProxyTable
      :items="items"
      :loading="loading"
      :checking-ids="checkingIds"
      @check="(proxy) => check(proxy.id)"
      @history="showHistory"
      @edit="openEdit"
      @delete="askDelete"
    />

    <Pagination :meta="meta" @change="setPage" />

    <ProxyFormModal
      :open="formOpen"
      :proxy="editingProxy"
      :saving="saving"
      :field-errors="fieldErrors"
      @close="closeForm"
      @submit="submitForm"
    />

    <ProxyChecksDrawer
      :open="historyOpen"
      :proxy="historyProxy"
      :checks="checks"
      :loading="checksLoading"
      :error="checksError"
      @close="closeHistory"
    />

    <ConfirmDialog
      :open="Boolean(deleteProxyTarget)"
      title="Delete proxy"
      :message="deleteMessage"
      :loading="saving"
      @cancel="deleteProxyTarget = null"
      @confirm="confirmDelete"
    />
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useProxies } from '../composables/useProxies';
import EmptyState from '../components/ui/EmptyState.vue';
import LoadingButton from '../components/ui/LoadingButton.vue';
import Pagination from '../components/ui/Pagination.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import ProxyChecksDrawer from '../components/proxies/ProxyChecksDrawer.vue';
import ProxyFormModal from '../components/proxies/ProxyFormModal.vue';
import ProxyTable from '../components/proxies/ProxyTable.vue';
import type { ProxyPayload, ProxyServer } from '../types/proxy';

const {
  items,
  meta,
  checks,
  checksLoading,
  checksError,
  loading,
  saving,
  error,
  fieldErrors,
  filters,
  checkingIds,
  clearErrors,
  clearChecks,
  load,
  create,
  update,
  remove,
  check,
  checkAll,
  openHistory,
} = useProxies();

const formOpen = ref(false);
const editingProxy = ref<ProxyServer | null>(null);
const historyOpen = ref(false);
const historyProxy = ref<ProxyServer | null>(null);
const deleteProxyTarget = ref<ProxyServer | null>(null);

let initialLoadDone = false;

const deleteMessage = computed(() => {
  const proxy = deleteProxyTarget.value;
  return proxy ? `Delete ${proxy.display_address}?` : 'Delete proxy?';
});

onMounted(async () => {
  await load();
  initialLoadDone = true;
});

watch(
  () => [filters.search, filters.status, filters.scheme, filters.sort, filters.direction],
  () => {
    if (!initialLoadDone) {
      return;
    }

    filters.page = 1;
    void load();
  },
);

watch(
  () => filters.page,
  () => {
    if (initialLoadDone) {
      void load();
    }
  },
);

function setPage(page: number) {
  filters.page = page;
}

function openCreate() {
  clearErrors();
  editingProxy.value = null;
  formOpen.value = true;
}

function openEdit(proxy: ProxyServer) {
  clearErrors();
  editingProxy.value = proxy;
  formOpen.value = true;
}

function closeForm() {
  clearErrors();
  formOpen.value = false;
  editingProxy.value = null;
}

async function submitForm(payload: ProxyPayload) {
  const proxy = editingProxy.value;
  const saved = proxy ? await update(proxy.id, payload) : await create(payload);

  if (saved) {
    closeForm();
  }
}

async function showHistory(proxy: ProxyServer) {
  clearChecks();
  historyProxy.value = proxy;
  historyOpen.value = true;
  await openHistory(proxy.id);
}

function closeHistory() {
  historyOpen.value = false;
  historyProxy.value = null;
  clearChecks();
}

function askDelete(proxy: ProxyServer) {
  deleteProxyTarget.value = proxy;
}

async function confirmDelete() {
  const proxy = deleteProxyTarget.value;

  if (!proxy) {
    return;
  }

  const deleted = await remove(proxy.id);

  if (deleted) {
    deleteProxyTarget.value = null;
  }
}
</script>
