import router from './router/router'
import { createAppStore } from './store';


// Enables the Composition API
(window as any).__VUE_OPTIONS_API__ = true;
// Disable devtools in production
(window as any).__VUE_PROD_DEVTOOLS__ = false;

declare const M: {
  cfg: {
    wwwroot: string;
  };
};

import { createApp } from 'vue';
import Taskflow from './components/NotFound.vue';

export function init(): void {
    const app = createApp(Taskflow);
    const store = createAppStore();
    store.dispatch('loadComponentStrings');
    app.use(store);
    app.use(router);

    app.mount('#local-taskflow-app');
}