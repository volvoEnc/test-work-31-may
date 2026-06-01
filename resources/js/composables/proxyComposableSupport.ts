import type { Ref } from 'vue';
import { ApiError } from '../api/http';
import type { PaginationMeta } from '../types/proxy';

export type FieldErrors = Record<string, string[]>;

export interface ProxyErrorState {
  error: Ref<string>;
  fieldErrors: Ref<FieldErrors>;
}

export const emptyProxyMeta = (): PaginationMeta => ({
  current_page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
});

export const isAbortError = (unknownError: unknown): boolean =>
  unknownError instanceof DOMException && unknownError.name === 'AbortError';

export const clearProxyErrors = ({ error, fieldErrors }: ProxyErrorState) => {
  error.value = '';
  fieldErrors.value = {};
};

export const applyProxyApiError = (
  unknownError: unknown,
  fallback: string,
  { error, fieldErrors }: ProxyErrorState,
) => {
  if (isAbortError(unknownError)) {
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
