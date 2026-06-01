import { ref } from 'vue';
import { clearProxyErrors, type FieldErrors } from './proxyComposableSupport';
import { useProxyHistory } from './useProxyHistory';
import { useProxyList } from './useProxyList';
import { useProxyMutations } from './useProxyMutations';
import { useProxyPolling } from './useProxyPolling';

export function useProxies() {
  const error = ref('');
  const fieldErrors = ref<FieldErrors>({});
  const errorState = { error, fieldErrors };

  const clearErrors = () => {
    clearProxyErrors(errorState);
  };

  const list = useProxyList(errorState);
  const polling = useProxyPolling({
    checkingIds: list.checkingIds,
    load: list.load,
  });
  const mutations = useProxyMutations({
    checkingIds: list.checkingIds,
    errorState,
    load: list.load,
    startFocusedPolling: polling.startFocusedPolling,
  });
  const history = useProxyHistory({ clearErrors });

  return {
    items: list.items,
    meta: list.meta,
    checks: history.checks,
    checksLoading: history.checksLoading,
    checksError: history.checksError,
    loading: list.loading,
    saving: mutations.saving,
    error,
    fieldErrors,
    filters: list.filters,
    checkingIds: list.checkingIds,
    clearErrors,
    clearChecks: history.clearChecks,
    load: list.load,
    create: mutations.create,
    update: mutations.update,
    remove: mutations.remove,
    check: mutations.check,
    checkAll: mutations.checkAll,
    openHistory: history.openHistory,
  };
}
