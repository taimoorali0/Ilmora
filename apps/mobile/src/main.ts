import { createApp } from 'vue';
import * as Ion from '@ionic/vue';

import '@ionic/vue/css/core.css';
import '@ionic/vue/css/normalize.css';
import '@ionic/vue/css/structure.css';
import '@ionic/vue/css/typography.css';
import '@ionic/vue/css/padding.css';
import '@ionic/vue/css/flex-utils.css';
import '@ionic/vue/css/display.css';
import './theme/ilmora.css';

import App from './App.vue';
import AppModal from './components/AppModal.vue';
import Letterhead from './components/Letterhead.vue';
import PageHeader from './components/PageHeader.vue';
import { router } from './router';
import { seedDemo } from './lib/demo';
import { IS_DEMO, loadSession, session } from './lib/session';
import { loadStore } from './lib/store';
import { startSync } from './lib/sync';

async function start() {
  await loadSession();
  const hadData = await loadStore();
  if (IS_DEMO && !hadData) seedDemo();
  if (session.data && !session.data.user.super) startSync();

  const app = createApp(App).use(Ion.IonicVue, { mode: 'md' }).use(router);
  // Ionic components are used on every screen: register them once.
  for (const [name, component] of Object.entries(Ion)) {
    if (/^Ion[A-Z]/.test(name) && name !== 'IonicVue' && typeof component === 'object') app.component(name, component as never);
  }
  app.component('PageHeader', PageHeader);
  app.component('AppModal', AppModal);
  app.component('Letterhead', Letterhead);
  await router.isReady();
  app.mount('#app');
}

start();
