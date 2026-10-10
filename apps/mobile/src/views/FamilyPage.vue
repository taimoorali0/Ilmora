<template>
  <ion-page>
    <ion-header>
      <ion-toolbar color="dark">
        <ion-buttons slot="start"><ion-back-button default-href="/families" /></ion-buttons>
        <ion-title>{{ isNew ? 'New family' : form.code }}</ion-title>
        <ion-buttons slot="end"><SyncBadge /></ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content class="ion-padding">
      <ion-note v-if="!canEdit" color="medium">View only.</ion-note>

      <form @submit.prevent="save">
        <ion-list lines="full">
          <ion-item>
            <ion-input v-model="form.primary_guardian_name" label="Father / guardian name *" label-placement="stacked"
              :readonly="!canEdit" :maxlength="100" required />
          </ion-item>
          <ion-item>
            <ion-input :value="form.primary_guardian_cnic" @ion-input="form.primary_guardian_cnic = formatCnic(String($event.detail.value ?? ''))"
              label="CNIC" label-placement="stacked" placeholder="12345-1234567-1" inputmode="numeric" :readonly="!canEdit" />
          </ion-item>
          <ion-item>
            <ion-input v-model="form.primary_phone" label="Phone *" label-placement="stacked" placeholder="0300-1234567"
              type="tel" :readonly="!canEdit" required />
          </ion-item>
          <ion-item>
            <ion-input v-model="form.whatsapp_phone" label="WhatsApp (if different)" label-placement="stacked" type="tel" :readonly="!canEdit" />
          </ion-item>
          <ion-item>
            <ion-textarea v-model="form.address" label="Address" label-placement="stacked" auto-grow :maxlength="300" :readonly="!canEdit" />
          </ion-item>
        </ion-list>

        <ion-note v-for="e in errors" :key="e" color="danger" class="err">{{ e }}</ion-note>
        <ion-button v-if="canEdit" type="submit" expand="block" class="ion-margin-top">{{ isNew ? 'Save family' : 'Save changes' }}</ion-button>
      </form>

      <template v-if="!isNew">
        <h3 class="section">Children</h3>
        <ion-list lines="full">
          <ion-item v-for="s in children ?? []" :key="s.id" button :router-link="`/families/${id}/students/${s.id}`">
            <ion-label>
              <h3>{{ s.name }}</h3>
              <p>Adm. {{ s.admission_no }} · {{ s.gender }}</p>
            </ion-label>
            <ion-badge slot="end" :color="s.status === 'active' ? 'primary' : 'medium'">{{ s.status }}</ion-badge>
          </ion-item>
        </ion-list>
        <ion-button v-if="canEdit" fill="outline" expand="block" :router-link="`/families/${id}/students/new`">
          <ion-icon :icon="personAdd" slot="start" /> Add child
        </ion-button>
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonBackButton, IonBadge, IonButton, IonButtons, IonContent, IonHeader, IonIcon, IonInput, IonItem, IonLabel,
  IonList, IonNote, IonPage, IonTextarea, IonTitle, IonToolbar, toastController, useIonRouter,
} from '@ionic/vue';
import { personAdd } from 'ionicons/icons';
import { usePowerSync, useQuery } from '@powersync/vue';
import { computed, reactive, ref, watch } from 'vue';
import SyncBadge from '@/components/SyncBadge.vue';
import { effectiveAccess, hasRole, session } from '@/lib/session';
import { CNIC_RE, EDITOR_ROLES, PHONE_RE, defaultBranchId, formatCnic, nextCode, normalizePhone } from '@/lib/records';

const props = defineProps<{ id: string }>();
const db = usePowerSync();
const router = useIonRouter();
const isNew = computed(() => props.id === 'new');
const canEdit = computed(() => hasRole(...EDITOR_ROLES) && effectiveAccess() === 'full');

const form = reactive({ code: '', primary_guardian_name: '', primary_guardian_cnic: '', primary_phone: '', whatsapp_phone: '', address: '' });
const errors = ref<string[]>([]);

const { data: existing } = useQuery<typeof form>(
  'SELECT code, primary_guardian_name, primary_guardian_cnic, primary_phone, whatsapp_phone, address FROM families WHERE id = ?',
  computed(() => [props.id]),
);
let loaded = false;
watch(existing, (rows) => {
  const r = rows?.[0];
  if (r && !loaded) { loaded = true; for (const k of Object.keys(form) as (keyof typeof form)[]) form[k] = r[k] ?? ''; }
}, { immediate: true });

const { data: children } = useQuery<{ id: string; name: string; admission_no: string; gender: string; status: string }>(
  'SELECT id, name, admission_no, gender, status FROM students WHERE family_id = ? ORDER BY date_of_birth, name',
  computed(() => [props.id]),
);

function validate(): boolean {
  const e: string[] = [];
  if (form.primary_guardian_name.trim().length < 2) e.push('Enter the father / guardian name.');
  if (!PHONE_RE.test(normalizePhone(form.primary_phone))) e.push('Phone must be a Pakistani mobile number, e.g. 0300-1234567.');
  if (form.whatsapp_phone && !PHONE_RE.test(normalizePhone(form.whatsapp_phone))) e.push('WhatsApp number is not valid.');
  if (form.primary_guardian_cnic && !CNIC_RE.test(form.primary_guardian_cnic)) e.push('CNIC must look like 12345-1234567-1.');
  errors.value = e;
  return e.length === 0;
}

async function save() {
  if (!canEdit.value || !validate()) return;
  const values = {
    primary_guardian_name: form.primary_guardian_name.trim(),
    primary_guardian_cnic: form.primary_guardian_cnic || null,
    primary_phone: normalizePhone(form.primary_phone),
    whatsapp_phone: form.whatsapp_phone ? normalizePhone(form.whatsapp_phone) : null,
    address: form.address.trim() || null,
  };

  if (isNew.value) {
    const branch = defaultBranchId();
    if (!branch) { errors.value = ['Your account has no branch. Ask the principal to assign one.']; return; }
    const id = crypto.randomUUID();
    const code = await nextCode(db.value, 'family', 'F');
    await db.value.execute(
      `INSERT INTO families (id, school_id, branch_id, version, code, primary_guardian_name, primary_guardian_cnic,
         primary_phone, whatsapp_phone, address) VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?)`,
      [id, session.data?.user.school?.id, branch, code, values.primary_guardian_name, values.primary_guardian_cnic,
        values.primary_phone, values.whatsapp_phone, values.address],
    );
    router.replace(`/families/${id}`);
  } else {
    await db.value.execute(
      `UPDATE families SET primary_guardian_name = ?, primary_guardian_cnic = ?, primary_phone = ?, whatsapp_phone = ?, address = ?
       WHERE id = ?`,
      [values.primary_guardian_name, values.primary_guardian_cnic, values.primary_phone, values.whatsapp_phone, values.address, props.id],
    );
  }
  (await toastController.create({ message: 'Saved. It will sync when online.', duration: 1800, color: 'primary' })).present();
}
</script>

<style scoped>
.section { margin: 24px 0 4px; font-weight: 600; }
.err { display: block; margin-top: 6px; }
</style>
