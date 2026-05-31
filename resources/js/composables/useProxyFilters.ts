import { reactive } from 'vue';
import type { ProxyFilters } from '../types/proxy';

export function useProxyFilters(): ProxyFilters {
  return reactive<ProxyFilters>({
    search: '',
    status: '',
    scheme: '',
    page: 1,
    per_page: 20,
    sort: 'created_at',
    direction: 'desc',
  });
}
