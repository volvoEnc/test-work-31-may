import { ref } from 'vue';
import {
  checkAllProxies,
  checkProxy,
  createProxy,
  deleteProxy,
  updateProxy,
} from '../api/proxies';
import { applyProxyApiError, clearProxyErrors, type ProxyErrorState } from './proxyComposableSupport';
import type { ProxyPayload } from '../types/proxy';

interface UseProxyMutationsOptions {
  checkingIds: Set<number>;
  errorState: ProxyErrorState;
  load: () => Promise<void>;
  startFocusedPolling: (id?: number) => void;
}

export function useProxyMutations({
  checkingIds,
  errorState,
  load,
  startFocusedPolling,
}: UseProxyMutationsOptions) {
  const saving = ref(false);

  const save = async (operation: () => Promise<unknown>) => {
    clearProxyErrors(errorState);
    saving.value = true;

    try {
      await operation();
      await load();
      return true;
    } catch (unknownError) {
      applyProxyApiError(unknownError, 'Unable to save proxy.', errorState);
      return false;
    } finally {
      saving.value = false;
    }
  };

  const create = (payload: ProxyPayload) => save(() => createProxy(payload));

  const update = (id: number, payload: ProxyPayload) => save(() => updateProxy(id, payload));

  const remove = async (id: number) => {
    clearProxyErrors(errorState);
    saving.value = true;

    try {
      await deleteProxy(id);
      await load();
      return true;
    } catch (unknownError) {
      applyProxyApiError(unknownError, 'Unable to delete proxy.', errorState);
      return false;
    } finally {
      saving.value = false;
    }
  };

  const check = async (id: number) => {
    clearProxyErrors(errorState);
    checkingIds.add(id);

    try {
      await checkProxy(id);
      startFocusedPolling(id);
      return true;
    } catch (unknownError) {
      checkingIds.delete(id);
      applyProxyApiError(unknownError, 'Unable to queue check.', errorState);
      return false;
    }
  };

  const checkAll = async () => {
    clearProxyErrors(errorState);
    saving.value = true;

    try {
      await checkAllProxies();
      await load();
      if (checkingIds.size > 0) {
        startFocusedPolling();
      }
      return true;
    } catch (unknownError) {
      applyProxyApiError(unknownError, 'Unable to queue checks.', errorState);
      return false;
    } finally {
      saving.value = false;
    }
  };

  return {
    saving,
    create,
    update,
    remove,
    check,
    checkAll,
  };
}
