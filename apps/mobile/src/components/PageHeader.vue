<template>
  <ion-header>
    <ion-toolbar color="dark">
      <ion-buttons slot="start">
        <button v-if="back" class="hbtn" aria-label="Back" @click="goBack(back ?? '/home')"><ion-icon :icon="arrowBack" /></button>
        <ion-menu-button v-else />
      </ion-buttons>
      <ion-title>{{ title }}</ion-title>
      <ion-buttons slot="end">
        <slot />
        <SyncBadge />
      </ion-buttons>
    </ion-toolbar>
  </ion-header>
</template>

<script setup lang="ts">
import { arrowBack } from 'ionicons/icons';
import { goBack } from '@/router';
import SyncBadge from './SyncBadge.vue';

defineProps<{ title: string; back?: string }>();

</script>

<style scoped>
.hbtn { appearance: none; background: transparent; border: 0; color: inherit; width: 44px; height: 44px; display: grid; place-items: center; font-size: 1.4rem; cursor: pointer; border-radius: 50%; }
.hbtn:focus-visible { outline: 2px solid #fff; }
</style>
