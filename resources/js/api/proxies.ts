import { requestJson } from './http';
import type { PaginationMeta, ProxyCheck, ProxyFilters, ProxyPayload, ProxyServer } from '../types/proxy';

export interface CollectionResponse<T> {
  data: T[];
  meta: PaginationMeta;
}

export interface ItemResponse<T> {
  data: T;
}

export interface CheckQueuedResponse {
  queued: boolean;
  id?: number;
  status?: ProxyServer['status'];
  candidate_count?: number;
}

type QueryValue = string | number | boolean | null | undefined;

function toQueryString(params: object): string {
  const query = new URLSearchParams();

  Object.entries(params as Record<string, QueryValue>).forEach(([key, value]) => {
    if (value === '' || value === null || value === undefined) {
      return;
    }

    query.set(key, String(value));
  });

  const serialized = query.toString();

  return serialized ? `?${serialized}` : '';
}

export function listProxies(params: ProxyFilters, signal?: AbortSignal): Promise<CollectionResponse<ProxyServer>> {
  return requestJson<CollectionResponse<ProxyServer>>(`/proxies${toQueryString(params)}`, { signal });
}

export function createProxy(payload: ProxyPayload): Promise<ItemResponse<ProxyServer>> {
  return requestJson<ItemResponse<ProxyServer>>('/proxies', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function updateProxy(id: number, payload: ProxyPayload): Promise<ItemResponse<ProxyServer>> {
  return requestJson<ItemResponse<ProxyServer>>(`/proxies/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  });
}

export function deleteProxy(id: number): Promise<void> {
  return requestJson<void>(`/proxies/${id}`, { method: 'DELETE' });
}

export function checkProxy(id: number): Promise<ItemResponse<CheckQueuedResponse>> {
  return requestJson<ItemResponse<CheckQueuedResponse>>(`/proxies/${id}/check`, { method: 'POST' });
}

export function checkAllProxies(): Promise<ItemResponse<CheckQueuedResponse>> {
  return requestJson<ItemResponse<CheckQueuedResponse>>('/proxies/check', { method: 'POST' });
}

export function listProxyChecks(
  id: number,
  params: Pick<ProxyFilters, 'page' | 'per_page'> = { page: 1, per_page: 20 },
): Promise<CollectionResponse<ProxyCheck>> {
  return requestJson<CollectionResponse<ProxyCheck>>(`/proxies/${id}/checks${toQueryString(params)}`);
}
