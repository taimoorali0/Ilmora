<template>
  <ion-chip :color="color" @click="$router.push('/sync-lab')" :aria-label="label">
    <ion-icon :icon="icon" />
    <ion-label>{{ label }}</ion-label>
  </ion-chip>
</template>

<script setup lang="ts">
import { IonChip, IonIcon, IonLabel } from '@ionic/vue';
import { cloudDoneOutline, cloudOfflineOutline, syncOutline, lockClosedOutline } from 'ionicons/icons';
import { useQuery, useStatus } from '@powersync/vue';
import { computed } from 'vue';
import { effectiveAccess } from '@/lib/session';

const status = useStatus();
// Number of local writes not yet accepted by the server.
const { data: pending } = useQuery<{ n: number }>('SELECT count(*) AS n FROM ps_crud');

const queued = computed(() => pending.value?.[0]?.n ?? 0);
const access = computed(() => effectiveAccess());

const label = computed(() => {
  if (access.value === 'read_only') return 'Read-only: reconnect';
  if (!status.value.connected) return queued.value ? `Offline · ${queued.value} pending` : 'Offline';
  if (queued.value) return `Syncing ${queued.value}`;
  return 'All synced';
});
const color = computed(() =>
  access.value === 'read_only' ? 'danger' : !status.value.connected ? 'warning' : queued.value ? 'secondary' : 'primary',
);
const icon = computed(() =>
  access.value === 'read_only' ? lockClosedOutline
    : !status.value.connected ? cloudOfflineOutline : queued.value ? syncOutline : cloudDoneOutline,
);
</script>
