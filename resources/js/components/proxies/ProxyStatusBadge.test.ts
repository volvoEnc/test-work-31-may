import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ProxyStatusBadge from './ProxyStatusBadge.vue';
import type { ProxyStatus } from '../../types/proxy';

describe('ProxyStatusBadge', () => {
  it.each<ProxyStatus>(['unknown', 'checking', 'online', 'offline'])(
    'renders the %s status text and modifier class',
    (status) => {
      const wrapper = mount(ProxyStatusBadge, {
        props: { status },
      });

      expect(wrapper.text()).toBe(status);
      expect(wrapper.classes()).toContain(`status-badge--${status}`);
    },
  );
});
