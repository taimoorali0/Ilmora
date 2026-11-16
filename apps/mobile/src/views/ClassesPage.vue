<template>
  <ion-page>
    <PageHeader title="Classes" />
    <ion-content>
      <div class="wrap">
        <div class="row spread" style="margin-bottom:12px">
          <p class="muted" style="margin:0">Session {{ currentSession()?.name }} · {{ rows.length }} sections · {{ total }} students</p>
          <div class="row" v-if="canEdit">
            <button class="btn" @click="openClass">Add class</button>
            <button class="btn primary" @click="openSection()"><ion-icon :icon="add" /> Add section</button>
          </div>
        </div>
        <div class="card flush">
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>Class</th><th>Class teacher</th><th class="right">Students</th><th class="right">Capacity</th><th class="right">Monthly fee</th><th>Subjects</th><th></th></tr></thead>
              <tbody>
                <tr v-for="r in rows" :key="r.section.id">
                  <td><b>{{ sectionLabel(r.section.id) }}</b></td>
                  <td>{{ staffName(r.section.class_teacher_id) || '–' }}</td>
                  <td class="right num">{{ r.count }}</td>
                  <td class="right num">{{ r.section.capacity ?? '–' }}</td>
                  <td class="right num">{{ r.fee ? money(r.fee) : '–' }}</td>
                  <td>{{ r.subjects }}</td>
                  <td class="right nowrap">
                    <button class="btn sm" @click="$router.push({ path: '/attendance', query: { section: r.section.id } })">Attendance</button>
                    <button v-if="canEdit" class="btn sm" @click="openSection(r.section)">Edit</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="!rows.length" class="empty">No sections yet. Add a section to start admissions.</p>
        </div>
      </div>

      <AppModal :open="modal === 'section'" :title="form.id ? 'Edit section' : 'Add section'" @close="modal = ''">
        <form class="form" @submit.prevent="saveSection">
          <label class="field"><span>Class *</span><select v-model="form.class_id" :disabled="!!form.id" required><option v-for="c in sortedClasses()" :key="c.id" :value="c.id">{{ className(c.id) }}</option></select></label>
          <label class="field"><span>Section name *</span><input v-model="form.name" maxlength="20" placeholder="A" required /></label>
          <label class="field"><span>Class teacher</span><select v-model="form.class_teacher_id"><option value="">Not assigned</option><option v-for="s in teachers" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
          <label class="field"><span>Capacity</span><input v-model.number="form.capacity" type="number" min="1" max="200" /></label>
          <p v-if="error" class="error wide" role="alert">{{ error }}</p>
          <div class="actions wide"><button type="button" class="btn" @click="modal = ''">Cancel</button><button class="btn primary" type="submit">Save</button></div>
        </form>
      </AppModal>
      <AppModal :open="modal === 'class'" title="Add class" @close="modal = ''">
        <form class="form" @submit.prevent="saveClass">
          <label class="field wide"><span>Class name *</span><input v-model="newClass" maxlength="30" placeholder="e.g. 11 or O Level" required /></label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = ''">Cancel</button><button class="btn primary" type="submit">Save</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { add } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { money } from '@/lib/format';
import { className, currentSession, isManager, sectionLabel, sectionStudents, sortedClasses, sortedSections, staffName, subjectName } from '@/lib/school';
import { canWrite } from '@/lib/session';
import { all, groupBy, insert, update, type Row } from '@/lib/store';
import { toast } from '@/lib/ui';

const canEdit = computed(() => isManager() && canWrite());
const teachers = computed(() => all('staff').filter((s) => s.status !== 'left').sort((a, b) => a.name.localeCompare(b.name)));
const tuition = computed(() => all('fee_heads').find((h) => h.frequency === 'monthly'));
const rows = computed(() => sortedSections().map((section) => ({
  section,
  count: sectionStudents(section.id).length,
  fee: all('fee_structures').find((f) => f.class_id === section.class_id && f.fee_head_id === tuition.value?.id)?.amount ?? 0,
  subjects: (groupBy('section_subjects', 'section_id').get(section.id) ?? []).map((x) => subjectName(x.subject_id)).join(', ') || '–',
})));
const total = computed(() => rows.value.reduce((s, r) => s + r.count, 0));

const modal = ref<'' | 'section' | 'class'>('');
const error = ref('');
const form = reactive({ id: '', class_id: '', name: 'A', class_teacher_id: '', capacity: 30 });
function openSection(s?: Row) {
  Object.assign(form, s ? { id: s.id, class_id: s.class_id, name: s.name, class_teacher_id: s.class_teacher_id ?? '', capacity: s.capacity ?? 30 }
    : { id: '', class_id: sortedClasses()[0]?.id ?? '', name: 'B', class_teacher_id: '', capacity: 30 });
  error.value = ''; modal.value = 'section';
}
function saveSection() {
  const name = form.name.trim().toUpperCase();
  const sid = currentSession()?.id;
  if (all('sections').some((s) => s.class_id === form.class_id && s.academic_session_id === sid && s.name === name && s.id !== form.id)) { error.value = 'This class already has a section with that name.'; return; }
  const values = { name, class_teacher_id: form.class_teacher_id || null, capacity: Number(form.capacity) || null };
  if (form.id) update('sections', form.id, values);
  else insert('sections', { class_id: form.class_id, academic_session_id: sid, ...values });
  modal.value = ''; toast('Section saved.');
}
const newClass = ref('');
function openClass() { newClass.value = ''; modal.value = 'class'; }
function saveClass() {
  const name = newClass.value.trim();
  if (all('classes').some((c) => c.name.toLowerCase() === name.toLowerCase())) { toast('This class already exists.', 'bad'); return; }
  const c = insert('classes', { name, sort_order: Math.max(0, ...all('classes').map((x) => x.sort_order)) + 1, active: true });
  insert('sections', { class_id: c.id, academic_session_id: currentSession()?.id, name: 'A', class_teacher_id: null, capacity: 30 });
  modal.value = ''; toast(`${name} added with section A.`);
}
</script>
