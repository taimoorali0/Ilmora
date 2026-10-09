<template>
  <ion-app>
    <ion-router-outlet />
  </ion-app>
</template>

<script setup lang="ts">
import { IonApp, IonRouterOutlet } from '@ionic/vue';
import { onMounted } from 'vue';
import { session } from './lib/session';
import { connectSync } from './powersync/db';
import { startHeartbeat } from './lib/heartbeat';

onMounted(() => {
  // Signed in from a previous run: start syncing; works offline, catches up when online.
  if (session.data) {
    connectSync();
    startHeartbeat();
  }
});
</script>
