import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProxyFormModal from './components/proxies/ProxyFormModal.vue';

describe('frontend test harness', () => {
  it('runs vitest', () => {
    expect(true).toBe(true);
  });
});

describe('requestJson', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.resetModules();
  });

  it('returns parsed JSON and sends JSON headers for request bodies', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: { ok: true } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    const { requestJson } = await import('./api/http');
    const result = await requestJson<{ data: { ok: boolean } }>('/proxies', {
      method: 'POST',
      body: JSON.stringify({ host: '127.0.0.1' }),
    });

    expect(result.data.ok).toBe(true);
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = init.headers as Headers;
    expect(url).toBe('/api/v1/proxies');
    expect(init.method).toBe('POST');
    expect(headers.get('Accept')).toBe('application/json');
    expect(headers.get('Content-Type')).toBe('application/json');
  });

  it('returns undefined for empty 204 responses', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(null, { status: 204 })));

    const { requestJson } = await import('./api/http');

    await expect(requestJson('/proxies/1', { method: 'DELETE' })).resolves.toBeUndefined();
  });

  it('throws ApiError with status and field errors for failed JSON responses', async () => {
    const failedResponse = () =>
      new Response(
        JSON.stringify({
          message: 'Proxy already exists.',
          errors: { host: ['Duplicate proxy.'] },
        }),
        { status: 409, headers: { 'Content-Type': 'application/json' } },
      );

    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValueOnce(failedResponse()).mockResolvedValueOnce(failedResponse()),
    );

    const { ApiError, requestJson } = await import('./api/http');

    await expect(requestJson('/proxies', { method: 'POST' })).rejects.toMatchObject({
      name: 'ApiError',
      message: 'Proxy already exists.',
      status: 409,
      errors: { host: ['Duplicate proxy.'] },
    });
    await expect(requestJson('/proxies', { method: 'POST' })).rejects.toBeInstanceOf(ApiError);
  });

  it('throws for successful responses with malformed JSON', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response('{not-json', {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    );

    const { requestJson } = await import('./api/http');

    await expect(requestJson('/proxies')).rejects.toThrow('Unable to parse JSON response.');
  });

  it('falls back to default ApiError details when failed responses contain malformed JSON', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response('{not-json', {
          status: 500,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    );

    const { requestJson } = await import('./api/http');

    await expect(requestJson('/proxies')).rejects.toMatchObject({
      name: 'ApiError',
      message: 'Request failed with status 500',
      status: 500,
      errors: {},
    });
  });
});

describe('ProxyFormModal', () => {
  it('shows edit-password guidance as visible text', () => {
    const wrapper = mount(ProxyFormModal, {
      props: {
        open: true,
        proxy: {
          id: 1,
          name: null,
          scheme: 'http',
          host: '127.0.0.1',
          port: 8080,
          username: null,
          has_credentials: true,
          display_address: 'http://127.0.0.1:8080',
          status: 'unknown',
          checking_started_at: null,
          last_checked_at: null,
          last_success_at: null,
          response_time_ms: null,
          failure_reason: null,
          created_at: null,
          updated_at: null,
        },
      },
      global: {
        stubs: {
          Teleport: true,
        },
      },
    });

    expect(wrapper.text()).toContain('Leave empty to keep the current password.');
  });
});
