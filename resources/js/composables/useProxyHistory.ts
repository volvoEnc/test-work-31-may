import { ref } from 'vue';
import { listProxyChecks } from '../api/proxies';
import { ApiError } from '../api/http';
import type { ProxyCheck } from '../types/proxy';

interface UseProxyHistoryOptions {
  clearErrors: () => void;
}

export function useProxyHistory({ clearErrors }: UseProxyHistoryOptions) {
  const checks = ref<ProxyCheck[]>([]);
  const checksLoading = ref(false);
  const checksError = ref('');

  let historyRequestId = 0;

  const clearChecks = () => {
    checks.value = [];
    checksError.value = '';
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

  return {
    checks,
    checksLoading,
    checksError,
    clearChecks,
    openHistory,
  };
}
