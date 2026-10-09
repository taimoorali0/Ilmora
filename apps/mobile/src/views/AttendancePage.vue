<template>
  <ion-page>
    <ion-header>
      <ion-toolbar color="dark">
        <ion-buttons slot="start"><ion-back-button default-href="/home" /></ion-buttons>
        <ion-title>Attendance</ion-title>
        <ion-buttons slot="end"><SyncBadge /></ion-buttons>
      </ion-toolbar>
      <ion-toolbar>
        <ion-select v-model="sectionId" label="Section" interface="popover" class="ion-padding-horizontal">
          <ion-select-option v-for="s in sections ?? []" :key="s.section_id" :value="s.section_id">
            {{ s.section_id.slice(0, 8) }} · {{ s.students }} students
          </ion-select-option>
        </ion-select>
        <ion-input v-model="date" type="date" slot="end" class="date" aria-label="Date" />
      </ion-toolbar>
    </ion-header>

    <ion-content>
      <ion-note v-if="readOnly" class="ion-padding" color="danger">
        Offline access expired. Connect to the internet to keep marking attendance.
      </ion-note>

      <ion-list v-if="rows?.length" lines="full">
        <ion-item v-for="r in rows" :key="r.student_id">
          <ion-label>
            <h3>{{ r.name }}</h3>
            <p>Roll {{ r.roll_no }}</p>
          </ion-label>
          <ion-segment :value="r.status ?? ''" slot="end" :disabled="readOnly"
            @ion-change="mark(r, ($event.detail.value as string))">
            <ion-segment-button value="present" aria-label="Present"><ion-label>P</ion-label></ion-segment-button>
            <ion-segment-button value="absent" aria-label="Absent"><ion-label>A</ion-label></ion-segment-button>
            <ion-segment-button value="leave" aria-label="Leave"><ion-label>L</ion-label></ion-segment-button>
          </ion-segment>
        </ion-item>
      </ion-list>
      <p v-else class="ion-padding ilm-muted">No students for this section on this device yet. They appear after the first sync.</p>
    </ion-content>

    <ion-footer v-if="rows?.length">
      <ion-toolbar>
        <ion-button expand="block" fill="outline" :disabled="readOnly" @click="markAllPresent" class="ion-padding-horizontal">
          Mark all present
        </ion-button>
      </ion-toolbar>
    </ion-footer>
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonBackButton, IonButton, IonButtons, IonContent, IonFooter, IonHeader, IonInput, IonItem, IonLabel, IonList, IonNote,
  IonPage, IonSegment, IonSegmentButton, IonSelect, IonSelectOption, IonTitle, IonToolbar,
} from '@ionic/vue';
import { usePowerSync, useQuery } from '@powersync/vue';
import { computed, ref, watch } from 'vue';
import SyncBadge from '@/components/SyncBadge.vue';
import { effectiveAccess, session } from '@/lib/session';

interface Row { student_id: string; name: string; roll_no: string; branch_id: string; attendance_id: string | null; status: string | null }

const db = usePowerSync();
const date = ref(new Date().toISOString().slice(0, 10));
const sectionId = ref<string | null>(null);
const readOnly = computed(() => effectiveAccess() !== 'full');

const { data: sections } = useQuery<{ section_id: string; students: number }>(
  `SELECT section_id, count(*) AS students FROM enrollments WHERE status = 'active' GROUP BY section_id ORDER BY section_id`,
);
watch(sections, (s) => { if (!sectionId.value && s?.length) sectionId.value = s[0].section_id; }, { immediate: true });

const { data: rows } = useQuery<Row>(
  computed(() => `
    SELECT e.student_id, s.name, e.roll_no, s.branch_id, a.id AS attendance_id, a.status
    FROM enrollments e
    JOIN students s ON s.id = e.student_id
    LEFT JOIN student_attendance a ON a.student_id = e.student_id AND a.date = ?
    WHERE e.section_id = ? AND e.status = 'active'
    ORDER BY CAST(e.roll_no AS INTEGER), s.name`),
  computed(() => [date.value, sectionId.value ?? '']),
);

/** Insert or update locally; PowerSync queues it and uploads when online. */
async function mark(r: Row, status: string) {
  if (!status || readOnly.value || !sectionId.value) return;
  if (r.attendance_id) {
    await db.value.execute(`UPDATE student_attendance SET status = ? WHERE id = ?`, [status, r.attendance_id]);
  } else {
    await db.value.execute(
      `INSERT INTO student_attendance (id, school_id, branch_id, student_id, section_id, date, status, version)
       VALUES (?, ?, ?, ?, ?, ?, ?, 0)`,
      [crypto.randomUUID(), session.data?.user.school?.id, r.branch_id, r.student_id, sectionId.value, date.value, status],
    );
  }
}

async function markAllPresent() {
  for (const r of [...(rows.value ?? [])]) if (!r.status) await mark(r, 'present');
}
</script>

<style scoped>
.date { max-width: 160px; }
ion-segment { width: 150px; }
</style>
