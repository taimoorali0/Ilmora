<template>
  <ion-page>
    <ion-header>
      <ion-toolbar color="dark">
        <ion-buttons slot="start"><ion-back-button :default-href="`/families/${familyId}`" /></ion-buttons>
        <ion-title>{{ isNew ? 'New student' : form.name }}</ion-title>
        <ion-buttons slot="end"><SyncBadge /></ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content class="ion-padding">
      <ion-note v-if="!canEdit" color="medium">View only.</ion-note>
      <form @submit.prevent="save">
        <ion-list lines="full">
          <ion-item>
            <ion-input v-model="form.name" label="Student name *" label-placement="stacked" :maxlength="100" :readonly="!canEdit" required />
          </ion-item>
          <ion-item>
            <ion-select v-model="form.gender" label="Gender *" label-placement="stacked" interface="popover" :disabled="!canEdit">
              <ion-select-option value="male">Male</ion-select-option>
              <ion-select-option value="female">Female</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item>
            <ion-input v-model="form.date_of_birth" type="date" label="Date of birth" label-placement="stacked" :readonly="!canEdit" />
          </ion-item>
          <ion-item>
            <ion-input :value="form.b_form" @ion-input="form.b_form = formatCnic(String($event.detail.value ?? ''))"
              label="B-Form" label-placement="stacked" placeholder="12345-1234567-1" inputmode="numeric" :readonly="!canEdit" />
          </ion-item>
          <ion-item>
            <ion-input v-model="form.admission_date" type="date" label="Admission date *" label-placement="stacked" :readonly="!canEdit" />
          </ion-item>
          <ion-item v-if="!isNew">
            <ion-input :value="form.admission_no" label="Admission no." label-placement="stacked" readonly />
          </ion-item>
          <ion-item v-if="!isNew">
            <ion-select v-model="form.status" label="Status" label-placement="stacked" interface="popover" :disabled="!canEdit">
              <ion-select-option value="active">Active</ion-select-option>
              <ion-select-option value="left">Left</ion-select-option>
              <ion-select-option value="struck_off">Struck off</ion-select-option>
              <ion-select-option value="graduated">Graduated</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item v-if="form.status === 'left' || form.status === 'struck_off'">
            <ion-input v-model="form.left_on" type="date" label="Left on" label-placement="stacked" :readonly="!canEdit" />
          </ion-item>
          <ion-item>
            <ion-textarea v-model="form.notes" label="Medical / other notes" label-placement="stacked" auto-grow :maxlength="500" :readonly="!canEdit" />
          </ion-item>
        </ion-list>
        <ion-note v-for="e in errors" :key="e" color="danger" class="err">{{ e }}</ion-note>
        <ion-button v-if="canEdit" type="submit" expand="block" class="ion-margin-top">{{ isNew ? 'Admit student' : 'Save changes' }}</ion-button>
      </form>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonBackButton, IonButton, IonButtons, IonContent, IonHeader, IonInput, IonItem, IonList, IonNote, IonPage,
  IonSelect, IonSelectOption, IonTextarea, IonTitle, IonToolbar, toastController, useIonRouter,
} from '@ionic/vue';
import { usePowerSync, useQuery } from '@powersync/vue';
import { computed, reactive, ref, watch } from 'vue';
import SyncBadge from '@/components/SyncBadge.vue';
import { effectiveAccess, hasRole, session } from '@/lib/session';
import { CNIC_RE, EDITOR_ROLES, formatCnic, nextCode } from '@/lib/records';

const props = defineProps<{ familyId: string; id: string }>();
const db = usePowerSync();
const router = useIonRouter();
const isNew = computed(() => props.id === 'new');
const canEdit = computed(() => hasRole(...EDITOR_ROLES) && effectiveAccess() === 'full');
const today = new Date().toISOString().slice(0, 10);

const form = reactive({
  name: '', gender: '', date_of_birth: '', b_form: '', admission_date: today, admission_no: '',
  status: 'active', left_on: '', notes: '',
});
const errors = ref<string[]>([]);

const { data: existing } = useQuery<typeof form>(
  `SELECT name, gender, date_of_birth, b_form, admission_date, admission_no, status, left_on, notes
   FROM students WHERE id = ? AND family_id = ?`,
  computed(() => [props.id, props.familyId]),
);
let loaded = false;
watch(existing, (rows) => {
  const r = rows?.[0];
  if (r && !loaded) { loaded = true; for (const k of Object.keys(form) as (keyof typeof form)[]) form[k] = r[k] ?? ''; }
}, { immediate: true });

function validate(): boolean {
  const e: string[] = [];
  if (form.name.trim().length < 2) e.push('Enter the student name.');
  if (!form.gender) e.push('Choose a gender.');
  if (!form.admission_date) e.push('Enter the admission date.');
  if (form.date_of_birth && form.date_of_birth >= today) e.push('Date of birth must be in the past.');
  if (form.b_form && !CNIC_RE.test(form.b_form)) e.push('B-Form must look like 12345-1234567-1.');
  errors.value = e;
  return e.length === 0;
}

async function save() {
  if (!canEdit.value || !validate()) return;
  const v = {
    name: form.name.trim(), gender: form.gender, date_of_birth: form.date_of_birth || null, b_form: form.b_form || null,
    admission_date: form.admission_date, notes: form.notes.trim() || null,
  };

  if (isNew.value) {
    const fam = await db.value.getOptional<{ branch_id: string }>('SELECT branch_id FROM families WHERE id = ?', [props.familyId]);
    if (!fam) { errors.value = ['Family not found on this device.']; return; }
    const id = crypto.randomUUID();
    const admissionNo = await nextCode(db.value, 'admission', new Date().getFullYear().toString());
    await db.value.execute(
      `INSERT INTO students (id, school_id, branch_id, version, family_id, admission_no, name, gender, date_of_birth, b_form,
         admission_date, status, notes) VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 'active', ?)`,
      [id, session.data?.user.school?.id, fam.branch_id, props.familyId, admissionNo, v.name, v.gender, v.date_of_birth,
        v.b_form, v.admission_date, v.notes],
    );
    router.replace(`/families/${props.familyId}/students/${id}`);
  } else {
    const leaving = form.status === 'left' || form.status === 'struck_off';
    await db.value.execute(
      `UPDATE students SET name = ?, gender = ?, date_of_birth = ?, b_form = ?, admission_date = ?, status = ?, left_on = ?, notes = ?
       WHERE id = ? AND family_id = ?`,
      [v.name, v.gender, v.date_of_birth, v.b_form, v.admission_date, form.status, leaving ? (form.left_on || today) : null,
        v.notes, props.id, props.familyId],
    );
  }
  (await toastController.create({ message: 'Saved. It will sync when online.', duration: 1800, color: 'primary' })).present();
}
</script>

<style scoped>
.err { display: block; margin-top: 6px; }
</style>
