<template>
  <span class="badge" :class="state.kind" :title="state.title" role="status">
    <ion-icon :icon="state.icon" aria-hidden="true" /> {{ state.label }}
  </span>
</template>

<script setup lang="ts">
import { cloudDoneOutline, cloudOfflineOutline, flaskOutline, lockClosedOutline, syncOutline } from 'ionicons/icons';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { effectiveAccess, IS_DEMO } from '@/lib/session';
import { meta } from '@/lib/store';

const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const set = () => { online.value = navigator.onLine; };
onMounted(() => { window.addEventListener('online', set); window.addEventListener('offline', set); });
onUnmounted(() => { window.removeEventListener('online', set); window.removeEventListener('offline', set); });

const state = computed(() => {
  if (IS_DEMO) return { kind: 'demo', label: 'Demo', icon: flaskOutline, title: 'Sample data kept in this browser. Nothing is sent to a server.' };
  if (effectiveAccess() === 'read_only') return { kind: 'bad', label: 'Read-only', icon: lockClosedOutline, title: 'Connect to the internet to continue editing.' };
  if (!online.value) return { kind: 'warn', label: meta.pending ? `Offline · ${meta.pending}` : 'Offline', icon: cloudOfflineOutline, title: 'Changes are saved on this device and upload when you are online.' };
  if (meta.syncing || meta.pending) return { kind: 'info', label: meta.pending ? `Syncing ${meta.pending}` : 'Syncing', icon: syncOutline, title: 'Uploading changes' };
  if (meta.syncError) return { kind: 'warn', label: 'Sync problem', icon: cloudOfflineOutline, title: meta.syncError };
  return { kind: 'ok', label: 'Synced', icon: cloudDoneOutline, title: 'Everything is saved on the server.' };
});
</script>

<style scoped>
.badge { display: inline-flex; align-items: center; gap: 5px; font-size: 0.75rem; font-weight: 600; padding: 4px 10px; border-radius: 99px; margin-right: 8px; white-space: nowrap; background: rgba(255, 255, 255, 0.14); color: #fff; }
.badge.warn { background: #fbefd2; color: #8a5b0f; }
.badge.bad { background: #fde4e1; color: #b3261e; }
.badge.demo { background: #5f7def; }
</style>
