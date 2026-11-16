<template>
  <ion-page>
    <PageHeader title="Students & families" />
    <ion-content>
      <div class="wrap">
        <div class="row spread bar-top">
          <div class="seg">
            <button :class="{ on: tab === 'students' }" @click="tab = 'students'">Students ({{ students.length }})</button>
            <button :class="{ on: tab === 'families' }" @click="tab = 'families'">Families ({{ all('families').length }})</button>
          </div>
          <button v-if="canEdit" class="btn primary" @click="openNew"><ion-icon :icon="add" /> New admission</button>
        </div>
        <div class="row filters">
          <input class="input grow" v-model="search" type="search" placeholder="Search name, family code, phone, admission no" aria-label="Search" />
          <select v-if="tab === 'students'" class="input sel" v-model="sectionId" aria-label="Class">
            <option value="">All classes</option>
            <option v-for="s in sortedSections()" :key="s.id" :value="s.id">{{ sectionLabel(s.id) }}</option>
          </select>
        </div>

        <div class="card flush">
          <div class="list" v-if="tab === 'students'">
            <div v-for="s in shownStudents" :key="s.id" class="item click" @click="$router.push(`/students/${s.id}`)">
              <span class="avatar">{{ initials(s.name) }}</span>
              <div class="grow"><h4>{{ s.name }}</h4><p>{{ s.admission_no }} · {{ studentSection(s.id) || 'Not enrolled' }} · {{ find('families', s.family_id)?.primary_guardian_name }}</p></div>
              <span class="pill" :class="s.status === 'active' ? 'ok' : ''">{{ s.status }}</span>
            </div>
            <p v-if="!shownStudents.length" class="empty">No students match.</p>
          </div>
          <div class="list" v-else>
            <div v-for="f in shownFamilies" :key="f.id" class="item click" @click="$router.push(`/families/${f.id}`)">
              <span class="avatar">{{ initials(f.primary_guardian_name) }}</span>
              <div class="grow"><h4>{{ f.primary_guardian_name }} <span class="muted">· {{ f.code }}</span></h4><p>{{ kids(f.id) || 'No children yet' }} · {{ f.primary_phone }}</p></div>
              <span v-if="familyLedger(f.id).balance > 0" class="pill" :class="familyLedger(f.id).overdue > 0 ? 'bad' : 'warn'">{{ money(familyLedger(f.id).balance) }} due</span>
              <span v-else class="pill ok">Clear</span>
            </div>
            <p v-if="!shownFamilies.length" class="empty">No families match.</p>
          </div>
        </div>
        <p v-if="more" class="muted center">Showing the first {{ LIMIT }}. Search to narrow the list.</p>
      </div>

      <AppModal :open="adding" title="New admission" @close="adding = false">
        <form class="form" @submit.prevent="save">
          <label class="field wide"><span>Family</span>
            <select v-model="form.family_id">
              <option value="">New family</option>
              <option v-for="f in familyOptions" :key="f.id" :value="f.id">{{ f.primary_guardian_name }} · {{ f.code }}</option>
            </select>
          </label>
          <template v-if="!form.family_id">
            <label class="field"><span>Father / guardian name *</span><input v-model="form.guardian" maxlength="100" required /></label>
            <label class="field"><span>Phone *</span><input v-model="form.phone" inputmode="tel" placeholder="03001234567" required /></label>
            <label class="field"><span>CNIC</span><input :value="form.cnic" @input="form.cnic = formatCnic(($event.target as HTMLInputElement).value)" placeholder="12345-1234567-1" inputmode="numeric" /></label>
            <label class="field"><span>Address</span><input v-model="form.address" maxlength="300" /></label>
          </template>
          <label class="field"><span>Student name *</span><input v-model="form.name" maxlength="100" required /></label>
          <label class="field"><span>Gender</span><select v-model="form.gender"><option value="male">Boy</option><option value="female">Girl</option></select></label>
          <label class="field"><span>Date of birth</span><input v-model="form.dob" type="date" /></label>
          <label class="field"><span>Class *</span>
            <select v-model="form.section_id" required><option v-for="s in sortedSections()" :key="s.id" :value="s.id">{{ sectionLabel(s.id) }}</option></select>
          </label>
          <p v-if="error" class="error wide" role="alert">{{ error }}</p>
          <div class="actions wide"><button type="button" class="btn" @click="adding = false">Cancel</button><button class="btn primary" type="submit">Save admission</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { add } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { CNIC_RE, formatCnic, money, normalizePhone, PHONE_RE, today } from '@/lib/format';
import { familyLedger } from '@/lib/logic/fees';
import { admitStudent, newFamily } from '@/lib/records';
import { enrollmentOf, initials, isManager, sectionLabel, sortedSections, studentSection } from '@/lib/school';
import { canWrite } from '@/lib/session';
import { all, find, groupBy } from '@/lib/store';
import { toast } from '@/lib/ui';

const LIMIT = 150;
const router = useRouter();
const tab = ref<'students' | 'families'>('students');
const search = ref('');
const sectionId = ref('');
const canEdit = computed(() => isManager() && canWrite());
const students = computed(() => all('students').filter((s) => s.status === 'active'));
const q = computed(() => search.value.trim().toLowerCase());
const kids = (familyId: string) => (groupBy('students', 'family_id').get(familyId) ?? []).filter((s) => s.status === 'active').map((s) => s.name).join(', ');

const matchedStudents = computed(() => [...all('students')]
  .filter((s) => (!sectionId.value || enrollmentOf(s.id)?.section_id === sectionId.value)
    && (!q.value || [s.name, s.admission_no, find('families', s.family_id)?.primary_guardian_name, find('families', s.family_id)?.primary_phone].some((v) => String(v ?? '').toLowerCase().includes(q.value))))
  .sort((a, b) => a.name.localeCompare(b.name)));
const matchedFamilies = computed(() => [...all('families')]
  .filter((f) => !q.value || [f.primary_guardian_name, f.code, f.primary_phone, kids(f.id)].some((v) => String(v ?? '').toLowerCase().includes(q.value)))
  .sort((a, b) => a.primary_guardian_name.localeCompare(b.primary_guardian_name)));
const shownStudents = computed(() => matchedStudents.value.slice(0, LIMIT));
const shownFamilies = computed(() => matchedFamilies.value.slice(0, LIMIT));
const more = computed(() => (tab.value === 'students' ? matchedStudents.value.length : matchedFamilies.value.length) > LIMIT);
const familyOptions = computed(() => [...all('families')].sort((a, b) => a.primary_guardian_name.localeCompare(b.primary_guardian_name)));

const adding = ref(false);
const error = ref('');
const form = reactive({ family_id: '', guardian: '', phone: '', cnic: '', address: '', name: '', gender: 'male', dob: '', section_id: '' });
function openNew() {
  Object.assign(form, { family_id: '', guardian: '', phone: '', cnic: '', address: '', name: '', gender: 'male', dob: '', section_id: sectionId.value || sortedSections()[0]?.id || '' });
  error.value = '';
  adding.value = true;
}
function save() {
  error.value = '';
  let familyId = form.family_id;
  if (!familyId) {
    const phone = normalizePhone(form.phone);
    if (!PHONE_RE.test(phone)) { error.value = 'Phone must be a mobile number like 03001234567.'; return; }
    if (form.cnic && !CNIC_RE.test(form.cnic)) { error.value = 'CNIC must look like 12345-1234567-1.'; return; }
    const same = all('families').find((f) => f.primary_phone === phone || (form.cnic && f.primary_guardian_cnic === form.cnic));
    if (same) { error.value = `This phone or CNIC already belongs to ${same.primary_guardian_name} (${same.code}). Choose that family so siblings get their discount.`; return; }
    familyId = newFamily({ primary_guardian_name: form.guardian.trim(), primary_phone: phone, whatsapp_phone: phone, primary_guardian_cnic: form.cnic || null, address: form.address.trim() || null }).id;
  }
  if (!form.section_id) { error.value = 'Choose a class.'; return; }
  const student = admitStudent(familyId, { name: form.name.trim(), gender: form.gender, date_of_birth: form.dob || null, admission_date: today() }, form.section_id);
  adding.value = false;
  toast(`${student.name} admitted (${student.admission_no}).`);
  router.push(`/students/${student.id}`);
}
</script>

<style scoped>
.bar-top { margin-bottom: 12px; }
.filters { margin-bottom: 12px; }
.sel { width: auto; max-width: 200px; }
</style>
