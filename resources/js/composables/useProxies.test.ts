import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import type { PaginationMeta, ProxyCheck, ProxyServer } from '../types/proxy';

const proxyApiMock = vi.hoisted(() => ({
  checkAllProxies: vi.fn(),
  checkProxy: vi.fn(),
  createProxy: vi.fn(),
  deleteProxy: vi.fn(),
  listProxies: vi.fn(),
  listProxyChecks: vi.fn(),
  updateProxy: vi.fn(),
}));

vi.mock('../api/proxies', () => proxyApiMock);

const meta: PaginationMeta = { current_page: 1, per_page: 20, total: 1, last_page: 1 };

const proxy = (status: ProxyServer['status'], id = 7): ProxyServer => ({
  id,
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

async function mountUseProxies() {
  const { useProxies } = await import('./useProxies');
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

describe('useProxies load state', () => {
  afterEach(() => {
    vi.resetModules();
    vi.clearAllMocks();
  });

  it('updates items and meta from the list API', async () => {
    const listedProxy = proxy('online');
    proxyApiMock.listProxies.mockResolvedValue({ data: [listedProxy], meta });

    const { wrapper, proxies } = await mountUseProxies();

    await proxies.load();

    expect(proxyApiMock.listProxies).toHaveBeenCalledWith(
      expect.objectContaining({
        direction: 'desc',
        page: 1,
        per_page: 20,
        scheme: '',
        search: '',
        sort: 'created_at',
        status: '',
      }),
      expect.objectContaining({ aborted: false }),
    );
    expect(proxies.items.value).toEqual([listedProxy]);
    expect(proxies.meta.value).toEqual(meta);
    expect(proxies.loading.value).toBe(false);
    expect(proxies.error.value).toBe('');

    wrapper.unmount();
  });
});

describe('useProxies manual check polling', () => {
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

  it('does not reschedule focused polling after unmount while a focused load is pending', async () => {
    let resolveList!: (value: { data: ProxyServer[]; meta: PaginationMeta }) => void;
    proxyApiMock.listProxies.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveList = resolve;
        }),
    );

    const { wrapper, proxies } = await mountUseProxies();

    await expect(proxies.check(7)).resolves.toBe(true);

    await vi.advanceTimersByTimeAsync(1000);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(1);

    wrapper.unmount();
    resolveList({ data: [proxy('checking')], meta });
    await Promise.resolve();

    await vi.advanceTimersByTimeAsync(3000);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(1);
  });

  it('keeps polling refresh-all while any visible proxy is checking', async () => {
    proxyApiMock.checkAllProxies.mockResolvedValue({ data: { queued: true, candidate_count: 1 } });
    proxyApiMock.listProxies
      .mockResolvedValueOnce({ data: [proxy('checking')], meta })
      .mockResolvedValueOnce({ data: [proxy('checking')], meta })
      .mockResolvedValueOnce({ data: [proxy('online')], meta });

    const { wrapper, proxies } = await mountUseProxies();

    await expect(proxies.checkAll()).resolves.toBe(true);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(1000);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(2);

    await vi.advanceTimersByTimeAsync(3000);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(3);

    await vi.advanceTimersByTimeAsync(3000);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(3);

    wrapper.unmount();
  });

  it('does not focused-poll check-all when immediate reload has no visible checking proxies', async () => {
    proxyApiMock.checkAllProxies.mockResolvedValue({ data: { queued: true, candidate_count: 1 } });
    proxyApiMock.listProxies.mockResolvedValue({ data: [proxy('online')], meta });

    const { wrapper, proxies } = await mountUseProxies();

    await expect(proxies.checkAll()).resolves.toBe(true);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(1000);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(28999);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(1);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(2);

    wrapper.unmount();
  });

  it('clears stale history checks while a different proxy history loads', async () => {
    const firstCheck: ProxyCheck = {
      id: 1,
      source: 'manual',
      status: 'online',
      started_at: null,
      finished_at: null,
      response_time_ms: 42,
      http_status: 200,
      error_code: null,
      error_message: null,
    };

    proxyApiMock.listProxyChecks
      .mockResolvedValueOnce({ data: [firstCheck], meta })
      .mockImplementationOnce(() => new Promise(() => undefined));

    const { wrapper, proxies } = await mountUseProxies();

    await expect(proxies.openHistory(7)).resolves.toBe(true);
    expect(proxies.checks.value).toEqual([firstCheck]);

    void proxies.openHistory(8);

    expect(proxies.checks.value).toEqual([]);
    expect(proxies.checksLoading.value).toBe(true);

    wrapper.unmount();
  });

  it('stops focused polling at thirty seconds while allowing the global poll to continue', async () => {
    proxyApiMock.listProxies.mockResolvedValue({ data: [proxy('checking')], meta });

    const { wrapper, proxies } = await mountUseProxies();

    await expect(proxies.check(7)).resolves.toBe(true);
    await vi.advanceTimersByTimeAsync(29999);

    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(10);

    // The eleventh call at 30000ms is the composable's global list refresh,
    // not another focused poll for the still-checking proxy.
    await vi.advanceTimersByTimeAsync(1);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(11);

    await vi.advanceTimersByTimeAsync(3000);
    expect(proxyApiMock.listProxies).toHaveBeenCalledTimes(11);

    wrapper.unmount();
  });
});
