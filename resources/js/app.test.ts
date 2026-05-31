import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import ProxyFormModal from './components/proxies/ProxyFormModal.vue';
import type { ProxyServer } from './types/proxy';

const proxyApiMock = vi.hoisted(() => ({
  checkAllProxies: vi.fn(),
  checkProxy: vi.fn(),
  createProxy: vi.fn(),
  deleteProxy: vi.fn(),
  listProxies: vi.fn(),
  listProxyChecks: vi.fn(),
  updateProxy: vi.fn(),
}));

vi.mock('./api/proxies', () => proxyApiMock);

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

describe('useProxies manual check polling', () => {
  const meta = { current_page: 1, per_page: 20, total: 1, last_page: 1 };
  const proxy = (status: ProxyServer['status']): ProxyServer => ({
    id: 7,
    name: null,
    scheme: 'http',
    host: '127.0.0.1',
    port: 8080,
    username: null,
    has_credentials: false,
    display_address: 'http://127.0.0.1:8080',
    status,
    checking_started_at: null,
    last_checked_at: null,
    last_success_at: null,
    response_time_ms: null,
    failure_reason: null,
    created_at: null,
    updated_at: null,
  });

  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(0);
    proxyApiMock.checkProxy.mockResolvedValue({ data: { queued: true, id: 7, status: 'checking' } });
    proxyApiMock.listProxies.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.resetModules();
    vi.clearAllMocks();
  });

  async function mountUseProxies() {
    const { useProxies } = await import('./composables/useProxies');
    const exposed = { proxies: null as ReturnType<typeof useProxies> | null };
    const wrapper = mount(
      defineComponent({
        setup() {
          exposed.proxies = useProxies();
          return () => null;
        },
      }),
    );

    return { wrapper, proxies: exposed.proxies! };
  }

  it('reloads after one second, then polls every three seconds until status leaves checking', async () => {
    proxyApiMock.listProxies
      .mockResolvedValueOnce({ data: [proxy('checking')], meta })
      .mockResolvedValueOnce({ data: [proxy('checking')], meta })
      .mockResolvedValueOnce({ data: [proxy('online')], meta });

    const { wrapper, proxies } = await mountUseProxies();

    await expect(proxies.check(7)).resolves.toBe(true);
    expect(proxyApiMock.listProxies).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(999);
    expect(proxyApiMock.listProxies).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(1);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(2999);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(1);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(2);

    await vi.advanceTimersByTimeAsync(3000);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(3);

    await vi.advanceTimersByTimeAsync(3000);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(3);

    wrapper.unmount();
  });

  it('stops focused polling after thirty seconds even while status remains checking', async () => {
    proxyApiMock.listProxies.mockResolvedValue({ data: [proxy('checking')], meta });

    const { wrapper, proxies } = await mountUseProxies();

    await expect(proxies.check(7)).resolves.toBe(true);
    await vi.advanceTimersByTimeAsync(30000);

    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(11);

    await vi.advanceTimersByTimeAsync(3000);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(11);

    wrapper.unmount();
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
