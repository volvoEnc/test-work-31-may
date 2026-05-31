import { onUnmounted, reactive, ref } from 'vue';
import {
  checkAllProxies,
  checkProxy,
  createProxy,
  deleteProxy,
  listProxies,
  listProxyChecks,
  updateProxy,
} from '../api/proxies';
import { ApiError } from '../api/http';
import { useProxyFilters } from './useProxyFilters';
import type { PaginationMeta, ProxyCheck, ProxyPayload, ProxyServer } from '../types/proxy';

type FieldErrors = Record<string, string[]>;

const emptyMeta = (): PaginationMeta => ({
  current_page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
});

export function useProxies() {
  const items = ref<ProxyServer[]>([]);
  const meta = ref<PaginationMeta>(emptyMeta());
  const checks = ref<ProxyCheck[]>([]);
  const checksLoading = ref(false);
  const checksError = ref('');
  const loading = ref(false);
  const saving = ref(false);
  const error = ref('');
  const fieldErrors = ref<FieldErrors>({});
  const filters = useProxyFilters();
  const checkingIds = reactive(new Set<number>());

  let listController: AbortController | null = null;
  let pollIntervalId: number | undefined;
  let focusedPollTimeoutId: number | undefined;
  let focusedPollStartedAt: number | null = null;
  let historyRequestId = 0;

  const clearErrors = () => {
    error.value = '';
    fieldErrors.value = {};
  };

  const clearChecks = () => {
    checks.value = [];
    checksError.value = '';
  };

  const applyApiError = (unknownError: unknown, fallback: string) => {
    if (unknownError instanceof DOMException && unknownError.name === 'AbortError') {
      return;
    }

    if (unknownError instanceof ApiError) {
      error.value = unknownError.message;
      fieldErrors.value = unknownError.errors;
      return;
    }

    error.value = fallback;
    fieldErrors.value = {};
  };

  const refreshCheckingIds = () => {
    checkingIds.clear();
    items.value.forEach((proxy) => {
      if (proxy.status === 'checking') {
        checkingIds.add(proxy.id);
      }
    });
  };

  const load = async () => {
    listController?.abort();
    const controller = new AbortController();
    listController = controller;
    loading.value = true;

    try {
      const response = await listProxies({ ...filters }, controller.signal);
      items.value = response.data;
      meta.value = response.meta;
      error.value = '';
      refreshCheckingIds();
    } catch (unknownError) {
      applyApiError(unknownError, 'Unable to load proxies.');
    } finally {
      if (!controller.signal.aborted && listController === controller) {
        loading.value = false;
      }
    }
  };

  const save = async (operation: () => Promise<unknown>) => {
    clearErrors();
    saving.value = true;

    try {
      await operation();
      await load();
      return true;
    } catch (unknownError) {
      applyApiError(unknownError, 'Unable to save proxy.');
      return false;
    } finally {
      saving.value = false;
    }
  };

  const create = (payload: ProxyPayload) => save(() => createProxy(payload));

  const update = (id: number, payload: ProxyPayload) => save(() => updateProxy(id, payload));

  const remove = async (id: number) => {
    clearErrors();
    saving.value = true;

    try {
      await deleteProxy(id);
      await load();
      return true;
    } catch (unknownError) {
      applyApiError(unknownError, 'Unable to delete proxy.');
      return false;
    } finally {
      saving.value = false;
    }
  };

  const scheduleFocusedPolling = (id?: number) => {
    if (focusedPollTimeoutId !== undefined) {
      window.clearTimeout(focusedPollTimeoutId);
    }

    const startedAt = focusedPollStartedAt;

    if (startedAt === null) {
      focusedPollTimeoutId = undefined;
      return;
    }

    const elapsed = Date.now() - startedAt;

    if (elapsed >= 30000) {
      focusedPollTimeoutId = undefined;
      return;
    }

    const delay = elapsed === 0 ? 1000 : 3000;

    if (elapsed + delay > 30000) {
      focusedPollTimeoutId = undefined;
      return;
    }

    focusedPollTimeoutId = window.setTimeout(async () => {
      await load();

      focusedPollTimeoutId = undefined;

      const hasFocusedProxyChecking = id !== undefined ? checkingIds.has(id) : checkingIds.size > 0;

      if (hasFocusedProxyChecking && Date.now() - startedAt < 30000) {
        scheduleFocusedPolling(id);
      }
    }, delay);
  };

  const check = async (id: number) => {
    clearErrors();
    checkingIds.add(id);

    try {
      await checkProxy(id);
      focusedPollStartedAt = Date.now();
      scheduleFocusedPolling(id);
      return true;
    } catch (unknownError) {
      checkingIds.delete(id);
      applyApiError(unknownError, 'Unable to queue check.');
      return false;
    }
  };

  const checkAll = async () => {
    clearErrors();
    saving.value = true;

    try {
      await checkAllProxies();
      await load();
      focusedPollStartedAt = Date.now();
      scheduleFocusedPolling();
      return true;
    } catch (unknownError) {
      applyApiError(unknownError, 'Unable to queue checks.');
      return false;
    } finally {
      saving.value = false;
    }
  };

  const openHistory = async (id: number) => {
    clearErrors();
    clearChecks();
    checksLoading.value = true;
    const requestId = ++historyRequestId;

    try {
      const response = await listProxyChecks(id, { page: 1, per_page: 20 });
      if (requestId === historyRequestId) {
        checks.value = response.data;
      }
      return true;
    } catch (unknownError) {
      if (requestId === historyRequestId) {
        if (unknownError instanceof ApiError) {
          checksError.value = unknownError.message;
        } else {
          checksError.value = 'Unable to load check history.';
        }
      }
      return false;
    } finally {
      if (requestId === historyRequestId) {
        checksLoading.value = false;
      }
    }
  };

  pollIntervalId = window.setInterval(() => {
    void load();
  }, 30000);

  onUnmounted(() => {
    listController?.abort();

    if (pollIntervalId !== undefined) {
      window.clearInterval(pollIntervalId);
    }

    if (focusedPollTimeoutId !== undefined) {
      window.clearTimeout(focusedPollTimeoutId);
    }
  });

  return {
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
  };
}
