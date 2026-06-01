import { onUnmounted } from 'vue';

interface UseProxyPollingOptions {
  checkingIds: Set<number>;
  load: () => Promise<void>;
}

export function useProxyPolling({ checkingIds, load }: UseProxyPollingOptions) {
  let pollIntervalId: number | undefined;
  let focusedPollTimeoutId: number | undefined;
  let focusedPollStartedAt: number | null = null;
  let disposed = false;

  const scheduleFocusedPolling = (id?: number) => {
    if (disposed) {
      return;
    }

    if (focusedPollTimeoutId !== undefined) {
      window.clearTimeout(focusedPollTimeoutId);
    }

    const startedAt = focusedPollStartedAt;

    if (startedAt === null) {
      focusedPollTimeoutId = undefined;
      return;
    }

    const elapsed = Date.now() - startedAt;

    if (elapsed >= 30000) {
      focusedPollTimeoutId = undefined;
      return;
    }

    const delay = elapsed === 0 ? 1000 : 3000;

    if (elapsed + delay > 30000) {
      focusedPollTimeoutId = undefined;
      return;
    }

    focusedPollTimeoutId = window.setTimeout(async () => {
      await load();

      focusedPollTimeoutId = undefined;

      if (disposed) {
        return;
      }

      const hasFocusedProxyChecking = id !== undefined ? checkingIds.has(id) : checkingIds.size > 0;

      if (hasFocusedProxyChecking && Date.now() - startedAt < 30000) {
        scheduleFocusedPolling(id);
      }
    }, delay);
  };

  const startFocusedPolling = (id?: number) => {
    if (disposed) {
      return;
    }

    focusedPollStartedAt = Date.now();
    scheduleFocusedPolling(id);
  };

  pollIntervalId = window.setInterval(() => {
    if (disposed) {
      return;
    }

    void load();
  }, 30000);

  onUnmounted(() => {
    disposed = true;

    if (pollIntervalId !== undefined) {
      window.clearInterval(pollIntervalId);
    }

    if (focusedPollTimeoutId !== undefined) {
      window.clearTimeout(focusedPollTimeoutId);
    }
  });

  return {
    startFocusedPolling,
  };
}
