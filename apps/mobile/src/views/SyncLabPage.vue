<template>
  <ion-page>
    <ion-header>
      <ion-toolbar color="dark">
        <ion-buttons slot="start"><ion-back-button default-href="/home" /></ion-buttons>
        <ion-title>Sync lab</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content class="ion-padding">
      <p class="ilm-muted">Runs the PowerSync spike tests (docs/powersync-spike.md). Record results there.</p>

      <ion-card>
        <ion-card-header><ion-card-title>Status</ion-card-title></ion-card-header>
        <ion-card-content>
          <table class="kv">
            <tr><th>Platform</th><td>{{ platform }}</td></tr>
            <tr><th>Connected</th><td>{{ status.connected ? 'yes' : 'no' }}</td></tr>
            <tr><th>First sync done</th><td>{{ status.hasSynced ? 'yes' : 'no' }}</td></tr>
            <tr><th>Last synced</th><td>{{ status.lastSyncedAt?.toLocaleString() ?? '—' }}</td></tr>
            <tr><th>Queued writes</th><td>{{ queued }}</td></tr>
            <tr><th>Device</th><td>{{ session.data?.device.code }} · offline until {{ offlineUntil }}</td></tr>
            <tr><th>Access</th><td>{{ effectiveAccess() }}</td></tr>
          </table>
        </ion-card-content>
      </ion-card>

      <ion-card>
        <ion-card-header><ion-card-title>Rows on this device (S4)</ion-card-title></ion-card-header>
        <ion-card-content>
          <table class="kv">
            <tr v-for="c in counts ?? []" :key="c.t"><th>{{ c.t }}</th><td>{{ c.n }}</td></tr>
          </table>
        </ion-card-content>
      </ion-card>

      <ion-card>
        <ion-card-header><ion-card-title>Tests</ion-card-title></ion-card-header>
        <ion-card-content>
          <ion-button expand="block" @click="s2">S2 · Mark 30 test attendance rows</ion-button>
          <p class="ilm-muted">Turn on airplane mode first, then kill and reopen the app: rows and queue must survive.</p>
          <ion-button expand="block" color="secondary" @click="s6">S6 · Send same payment 3×</ion-button>
          <p class="ilm-muted">Needs internet. Expect: applied, ignored_duplicate, ignored_duplicate.</p>
          <ion-button expand="block" fill="outline" @click="s7">S7 · Time a local query</ion-button>
          <p v-if="log" class="log" role="status">{{ log }}</p>
        </ion-card-content>
      </ion-card>

      <ion-card v-if="uploadLog.at || uploadLog.error">
        <ion-card-header><ion-card-title>Last upload</ion-card-title></ion-card-header>
        <ion-card-content>
          <p v-if="uploadLog.error" class="err">{{ uploadLog.error }}</p>
          <p class="ilm-muted">{{ uploadLog.at }}</p>
          <p v-for="r in uploadLog.last.slice(0, 20)" :key="r.id + r.result">{{ r.table }} · {{ r.result }} {{ r.message ?? '' }}</p>
        </ion-card-content>
      </ion-card>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonBackButton, IonButton, IonButtons, IonCard, IonCardContent, IonCardHeader, IonCardTitle, IonContent, IonHeader,
  IonPage, IonTitle, IonToolbar,
} from '@ionic/vue';
import { Capacitor } from '@capacitor/core';
import { usePowerSync, useQuery, useStatus } from '@powersync/vue';
import { computed, ref } from 'vue';
import { api } from '@/lib/api';
import { effectiveAccess, session } from '@/lib/session';
import { uploadLog } from '@/powersync/connector';

const db = usePowerSync();
const status = useStatus();
const platform = Capacitor.getPlatform();
const log = ref('');

const { data: pending } = useQuery<{ n: number }>('SELECT count(*) AS n FROM ps_crud');
const queued = computed(() => pending.value?.[0]?.n ?? 0);
const offlineUntil = computed(() => new Date(session.data?.device.offline_valid_until ?? 0).toLocaleString());

const { data: counts } = useQuery<{ t: string; n: number }>(`
  SELECT 'students' AS t, count(*) AS n FROM students
  UNION ALL SELECT 'families', count(*) FROM families
  UNION ALL SELECT 'enrollments', count(*) FROM enrollments
  UNION ALL SELECT 'student_attendance', count(*) FROM student_attendance
  UNION ALL SELECT 'invoices', count(*) FROM invoices
  UNION ALL SELECT 'payments', count(*) FROM payments
  UNION ALL SELECT 'payslips', count(*) FROM payslips`);

/** S2: 30 attendance rows for today across students on this device. */
async function s2() {
  const students = await db.value.getAll<{ id: string; branch_id: string }>(
    `SELECT s.id, s.branch_id FROM students s
     WHERE NOT EXISTS (SELECT 1 FROM student_attendance a WHERE a.student_id = s.id AND a.date = date('now'))
     LIMIT 30`);
  if (!students.length) { log.value = 'No students without attendance today on this device.'; return; }
  const t0 = performance.now();
  for (const s of students) {
    const sec = await db.value.getOptional<{ section_id: string }>(
      'SELECT section_id FROM enrollments WHERE student_id = ? LIMIT 1', [s.id]);
    await db.value.execute(
      `INSERT INTO student_attendance (id, school_id, branch_id, student_id, section_id, date, status, version)
       VALUES (?, ?, ?, ?, ?, date('now'), 'present', 0)`,
      [crypto.randomUUID(), session.data?.user.school?.id, s.branch_id, s.id, sec?.section_id ?? null]);
  }
  log.value = `S2: wrote ${students.length} rows in ${Math.round(performance.now() - t0)} ms.`;
}

/** S6: replay one payment op three times straight to the API. */
async function s6() {
  const fam = await db.value.getOptional<{ id: string; branch_id: string }>('SELECT id, branch_id FROM families LIMIT 1');
  const branch = session.data?.user.branches[0];
  if (!fam || !branch || !session.data) { log.value = 'S6 needs at least one family synced to this device.'; return; }
  const op = {
    op: 'PUT', table: 'payments', id: crypto.randomUUID(),
    data: {
      branch_id: fam.branch_id, family_id: fam.id, amount: 100, method: 'cash',
      receipt_no: `${branch.code}-${session.data.device.code}-T${Date.now().toString().slice(-6)}`,
      received_at: new Date().toISOString(), received_by: session.data.user.id,
    },
  };
  const results: string[] = [];
  for (let i = 0; i < 3; i++) {
    const r = await api<{ results: { result: string }[] }>('/sync/upload', { method: 'POST', json: { ops: [op] } });
    results.push(r.results[0].result);
  }
  log.value = `S6: ${results.join(', ')}`;
}

/** S7: time a typical list query on local SQLite. */
async function s7() {
  const t0 = performance.now();
  const rows = await db.value.getAll(
    `SELECT s.name, f.primary_phone FROM students s LEFT JOIN families f ON f.id = s.family_id ORDER BY s.name LIMIT 500`);
  log.value = `S7: ${rows.length} rows in ${Math.round(performance.now() - t0)} ms.`;
}
</script>

<style scoped>
.kv { width: 100%; border-collapse: collapse; }
.kv th { text-align: left; font-weight: 500; padding: 4px 8px 4px 0; width: 45%; }
.kv td { padding: 4px 0; }
.log { margin-top: 12px; font-weight: 600; }
.err { color: var(--ion-color-danger); }
</style>
