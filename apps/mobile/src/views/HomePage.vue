<template>
  <ion-page>
    <ion-header>
      <ion-toolbar color="dark">
        <ion-title>
          <div class="brand"><img src="/ilmora-mark.png" alt="" /> {{ school }}</div>
        </ion-title>
        <ion-buttons slot="end"><SyncBadge /></ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content class="ion-padding">
      <h2 class="hello">Assalam-o-Alaikum, {{ name }}</h2>
      <p class="ilm-muted">{{ roleLine }}</p>

      <div class="stats">
        <ion-card><ion-card-content><b>{{ count('students') }}</b><span>Students on this device</span></ion-card-content></ion-card>
        <ion-card><ion-card-content><b>{{ count('families') }}</b><span>Families</span></ion-card-content></ion-card>
        <ion-card><ion-card-content><b>{{ presentToday }}</b><span>Marked present today</span></ion-card-content></ion-card>
      </div>

      <ion-list lines="full" class="menu">
        <ion-item button router-link="/attendance" v-if="hasRole('teacher', 'principal', 'owner', 'admin')">
          <ion-icon :icon="checkboxOutline" slot="start" color="primary" />
          <ion-label>Student attendance<p>Works offline</p></ion-label>
        </ion-item>
        <ion-item button router-link="/sync-lab">
          <ion-icon :icon="flaskOutline" slot="start" color="secondary" />
          <ion-label>Sync lab<p>PowerSync validation tests</p></ion-label>
        </ion-item>
        <ion-item button @click="signOut">
          <ion-icon :icon="logOutOutline" slot="start" color="medium" />
          <ion-label>Sign out</ion-label>
        </ion-item>
      </ion-list>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonButtons, IonCard, IonCardContent, IonContent, IonHeader, IonIcon, IonItem, IonLabel, IonList, IonPage,
  IonTitle, IonToolbar, alertController,
} from '@ionic/vue';
import { checkboxOutline, flaskOutline, logOutOutline } from 'ionicons/icons';
import { useQuery } from '@powersync/vue';
import { computed } from 'vue';
import SyncBadge from '@/components/SyncBadge.vue';
import { hasRole, session } from '@/lib/session';
import { wipeLocal } from '@/powersync/db';

const name = computed(() => session.data?.user.name ?? '');
const school = computed(() => session.data?.user.school?.name ?? 'Ilmora');
const roleLine = computed(() => session.data?.user.roles.map((r) => r.role).join(', ') ?? '');

const { data: counts } = useQuery<{ t: string; n: number }>(
  `SELECT 'students' AS t, count(*) AS n FROM students UNION ALL SELECT 'families', count(*) FROM families`,
);
const count = (t: string) => counts.value?.find((r) => r.t === t)?.n ?? 0;

const today = new Date().toISOString().slice(0, 10);
const { data: present } = useQuery<{ n: number }>(
  `SELECT count(*) AS n FROM student_attendance WHERE date = ? AND status = 'present'`, [today],
);
const presentToday = computed(() => present.value?.[0]?.n ?? 0);

async function signOut() {
  const alert = await alertController.create({
    header: 'Sign out?',
    message: 'Unsynced changes on this device will be lost.',
    buttons: [{ text: 'Cancel', role: 'cancel' }, { text: 'Sign out', role: 'destructive', handler: () => wipeLocal() }],
  });
  await alert.present();
}
</script>

<style scoped>
.brand { display: flex; align-items: center; gap: 8px; font-weight: 600; }
.brand img { width: 26px; height: 26px; }
.hello { margin: 8px 0 2px; font-weight: 600; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 4px; margin: 12px -10px; }
.stats b { display: block; font-size: 1.6rem; color: var(--ion-text-color); }
.stats span { font-size: 0.8rem; }
.menu { border-radius: 12px; overflow: hidden; margin-top: 8px; }
</style>
