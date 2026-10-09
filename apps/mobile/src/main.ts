import { createApp } from 'vue';
import { IonicVue } from '@ionic/vue';
import { createPowerSyncPlugin } from '@powersync/vue';

import '@ionic/vue/css/core.css';
import '@ionic/vue/css/normalize.css';
import '@ionic/vue/css/structure.css';
import '@ionic/vue/css/typography.css';
import '@ionic/vue/css/padding.css';
import '@ionic/vue/css/flex-utils.css';
import '@ionic/vue/css/display.css';
import './theme/ilmora.css';

import App from './App.vue';
import { router } from './router';
import { db } from './powersync/db';
import { loadSession } from './lib/session';

async function start() {
  await loadSession();
  await db.init();

  const app = createApp(App)
    .use(IonicVue)
    .use(router)
    .use(createPowerSyncPlugin({ database: db }));

  await router.isReady();
  app.mount('#app');
}

start();
