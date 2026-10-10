<template>
  <ion-page>
    <ion-header>
      <ion-toolbar color="dark">
        <ion-buttons slot="start"><ion-back-button default-href="/home" /></ion-buttons>
        <ion-title>Students &amp; families</ion-title>
        <ion-buttons slot="end"><SyncBadge /></ion-buttons>
      </ion-toolbar>
      <ion-toolbar>
        <ion-searchbar v-model="search" :debounce="200" placeholder="Name, family code, phone, admission no" />
      </ion-toolbar>
    </ion-header>

    <ion-content>
      <ion-list v-if="rows?.length" lines="full">
        <ion-item v-for="f in rows" :key="f.id" button :router-link="`/families/${f.id}`">
          <ion-label>
            <h3>{{ f.primary_guardian_name }} <span class="ilm-muted">· {{ f.code }}</span></h3>
            <p>{{ f.children || 'No children yet' }}</p>
          </ion-label>
          <ion-note slot="end">{{ f.primary_phone }}</ion-note>
        </ion-item>
      </ion-list>
      <p v-else class="ion-padding ilm-muted">
        {{ search ? 'No match on this device.' : 'No families on this device yet. Add one, or wait for the first sync.' }}
      </p>

      <ion-fab v-if="canEdit" slot="fixed" vertical="bottom" horizontal="end">
        <ion-fab-button router-link="/families/new" aria-label="Add family"><ion-icon :icon="add" /></ion-fab-button>
      </ion-fab>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonBackButton, IonButtons, IonContent, IonFab, IonFabButton, IonHeader, IonIcon, IonItem, IonLabel, IonList,
  IonNote, IonPage, IonSearchbar, IonTitle, IonToolbar,
} from '@ionic/vue';
import { add } from 'ionicons/icons';
import { useQuery } from '@powersync/vue';
import { computed, ref } from 'vue';
import SyncBadge from '@/components/SyncBadge.vue';
import { effectiveAccess, hasRole } from '@/lib/session';
import { EDITOR_ROLES } from '@/lib/records';

interface Row { id: string; code: string; primary_guardian_name: string; primary_phone: string; children: string | null }

const search = ref('');
const canEdit = computed(() => hasRole(...EDITOR_ROLES) && effectiveAccess() === 'full');

// Parameters only — search text is never concatenated into SQL.
const { data: rows } = useQuery<Row>(
  `SELECT f.id, f.code, f.primary_guardian_name, f.primary_phone,
          (SELECT group_concat(s.name, ', ') FROM students s WHERE s.family_id = f.id AND s.status = 'active') AS children
   FROM families f
   WHERE ?1 = ''
      OR f.primary_guardian_name LIKE ?2 OR f.code LIKE ?2 OR f.primary_phone LIKE ?2
      OR EXISTS (SELECT 1 FROM students s WHERE s.family_id = f.id AND (s.name LIKE ?2 OR s.admission_no LIKE ?2))
   ORDER BY f.primary_guardian_name
   LIMIT 200`,
  computed(() => [search.value.trim(), `%${search.value.trim()}%`]),
);
</script>
