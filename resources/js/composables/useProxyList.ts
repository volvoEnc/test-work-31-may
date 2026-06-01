import { onUnmounted, reactive, ref } from 'vue';
import { listProxies } from '../api/proxies';
import { useProxyFilters } from './useProxyFilters';
import { applyProxyApiError, emptyProxyMeta, isAbortError, type ProxyErrorState } from './proxyComposableSupport';
import type { PaginationMeta, ProxyServer } from '../types/proxy';

export function useProxyList(errorState: ProxyErrorState) {
  const items = ref<ProxyServer[]>([]);
  const meta = ref<PaginationMeta>(emptyProxyMeta());
  const loading = ref(false);
  const filters = useProxyFilters();
  const checkingIds = reactive(new Set<number>());

  let listController: AbortController | null = null;

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
      errorState.error.value = '';
      refreshCheckingIds();
    } catch (unknownError) {
      if (!isAbortError(unknownError)) {
        applyProxyApiError(unknownError, 'Unable to load proxies.', errorState);
      }
    } finally {
      if (!controller.signal.aborted && listController === controller) {
        loading.value = false;
      }
    }
  };

  onUnmounted(() => {
    listController?.abort();
  });

  return {
    items,
    meta,
    loading,
    filters,
    checkingIds,
    load,
  };
}
