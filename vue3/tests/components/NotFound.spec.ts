import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createRouter, createWebHistory } from 'vue-router';
import NotFound from '../../components/NotFound.vue';

describe('NotFound.vue', () => {
  const mockStore = createStore({
    state: {
      strings: {
        vueroutenotfoundsitename: 'Page not found',
        vueroutenotfoundsitedescription: 'Sorry, this page does not exist.',
        vueroutenotfoundsitebtnreload: 'Reload',
      },
    },
  });

  const mockRouter = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'home', component: {} },
    ],
  });

  it('renders strings from the Vuex store', async () => {
    mockRouter.push('/');
    await mockRouter.isReady();

    const wrapper = mount(NotFound, {
      global: {
        plugins: [mockStore, mockRouter],
      },
    });

    expect(wrapper.html()).toContain('Page not found');
    expect(wrapper.html()).toContain('Sorry, this page does not exist.');
    expect(wrapper.html()).toContain('Reload');
  });

  it('contains a router-link pointing to the correct route', async () => {
    mockRouter.push('/');
    await mockRouter.isReady();

    const wrapper = mount(NotFound, {
      global: {
        plugins: [mockStore, mockRouter],
      },
    });

    const routerLink = wrapper.findComponent({ name: 'RouterLink' });
    expect(routerLink.exists()).toBe(true);
    expect(routerLink.props('to')).toEqual({ name: 'home' });
  });
});