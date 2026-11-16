<template>
  <ion-page>
    <PageHeader :title="student ? student.name : 'Student'" back="/families" />
    <ion-content>
      <div class="wrap" v-if="student">
        <div class="card head">
          <span class="avatar big">{{ initials(student.name) }}</span>
          <div class="grow">
            <h2>{{ student.name }}</h2>
            <p class="muted">{{ student.admission_no }} · {{ studentSection(student.id) || 'Not enrolled' }} · Roll {{ enrollment?.roll_no ?? '–' }}</p>
          </div>
          <span class="pill" :class="student.status === 'active' ? 'ok' : ''">{{ student.status }}</span>
        </div>

        <div class="row acts" v-if="canEdit">
          <button class="btn" @click="openEdit">Edit student</button>
          <button class="btn" @click="openMove">Change class</button>
          <button v-if="student.status === 'active'" class="btn danger" @click="leave">Mark as left</button>
          <button v-else class="btn" @click="rejoin">Re-activate</button>
        </div>

        <div class="cols">
          <div class="card">
            <h3>Profile</h3>
            <dl class="kv">
              <dt>Gender</dt><dd>{{ student.gender === 'female' ? 'Girl' : 'Boy' }}</dd>
              <dt>Date of birth</dt><dd>{{ nice(student.date_of_birth) || '–' }}</dd>
              <dt>B-Form</dt><dd>{{ student.b_form || '–' }}</dd>
              <dt>Admission date</dt><dd>{{ nice(student.admission_date) || '–' }}</dd>
              <dt>Notes</dt><dd>{{ student.notes || '–' }}</dd>
            </dl>
          </div>
          <div class="card">
            <h3>Family</h3>
            <dl class="kv">
              <dt>Guardian</dt><dd>{{ family?.primary_guardian_name }}</dd>
              <dt>Phone</dt><dd>{{ family?.primary_phone }}</dd>
              <dt>Siblings</dt><dd>{{ siblings || 'None' }}</dd>
              <dt>Discount</dt><dd>{{ discount || 'None' }}</dd>
            </dl>
            <button class="btn sm" style="margin-top:12px" @click="$router.push(`/families/${student.family_id}`)">Open family and fees</button>
          </div>
        </div>

        <div class="card">
          <h3>Attendance, {{ niceMonth(period()) }}</h3>
          <div class="stats mini">
            <div class="stat"><b>{{ att.present }}</b><span>Present</span></div>
            <div class="stat"><b>{{ att.late }}</b><span>Late</span></div>
            <div class="stat"><b>{{ att.absent }}</b><span>Absent</span></div>
            <div class="stat"><b>{{ att.leave }}</b><span>Leave</span></div>
            <div class="stat"><b>{{ att.pct }}%</b><span>Attendance</span></div>
          </div>
          <div class="days" aria-label="Daily attendance this month">
            <span v-for="d in days" :key="d.date" class="day" :class="d.status" :title="`${nice(d.date)}: ${d.status}`">{{ Number(d.date.slice(8)) }}</span>
          </div>
        </div>

        <div class="card flush">
          <h3>Exam results</h3>
          <div class="list">
            <div v-for="r in results" :key="r.exam.id" class="item click" @click="$router.push(`/result/${r.exam.id}/${student.id}`)">
              <div class="grow"><h4>{{ r.exam.name }}</h4><p>{{ r.obtained }}/{{ r.total }} · {{ r.subjects }} subjects</p></div>
              <span class="pill" :class="r.pct >= 33 ? 'ok' : 'bad'">{{ r.pct }}% · {{ grade(r.pct) }}</span>
            </div>
            <p v-if="!results.length" class="empty">No marks entered yet.</p>
          </div>
        </div>
      </div>
      <p v-else class="empty">This student is not on this device.</p>

      <AppModal :open="modal === 'edit'" title="Edit student" @close="modal = ''">
        <form class="form" @submit.prevent="save">
          <label class="field"><span>Name *</span><input v-model="form.name" maxlength="100" required /></label>
          <label class="field"><span>Gender</span><select v-model="form.gender"><option value="male">Boy</option><option value="female">Girl</option></select></label>
          <label class="field"><span>Date of birth</span><input v-model="form.date_of_birth" type="date" /></label>
          <label class="field"><span>B-Form</span><input :value="form.b_form" @input="form.b_form = formatCnic(($event.target as HTMLInputElement).value)" placeholder="12345-1234567-1" /></label>
          <label class="field wide"><span>Notes</span><textarea v-model="form.notes" maxlength="500"></textarea></label>
          <p v-if="error" class="error wide" role="alert">{{ error }}</p>
          <div class="actions wide"><button type="button" class="btn" @click="modal = ''">Cancel</button><button class="btn primary" type="submit">Save</button></div>
        </form>
      </AppModal>
      <AppModal :open="modal === 'move'" title="Change class" @close="modal = ''">
        <form class="form" @submit.prevent="move">
          <label class="field wide"><span>New class</span><select v-model="moveTo" required><option v-for="s in sortedSections()" :key="s.id" :value="s.id">{{ sectionLabel(s.id) }}</option></select></label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = ''">Cancel</button><button class="btn primary" type="submit">Move student</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { CNIC_RE, daysInMonth, formatCnic, grade, nice, niceMonth, percent, period, today } from '@/lib/format';
import { nextRoll } from '@/lib/records';
import { examResult } from '@/lib/logic/exams';
import { enrollmentOf, initials, isManager, sectionLabel, sortedSections, studentSection } from '@/lib/school';
import { canWrite } from '@/lib/session';
import { all, find, groupBy, insert, update } from '@/lib/store';
import { ask, toast } from '@/lib/ui';

const props = defineProps<{ id: string }>();
const student = computed(() => find('students', props.id));
const family = computed(() => find('families', student.value?.family_id));
const enrollment = computed(() => enrollmentOf(props.id));
const canEdit = computed(() => isManager() && canWrite());
const siblings = computed(() => (groupBy('students', 'family_id').get(student.value?.family_id) ?? []).filter((s) => s.id !== props.id && s.status === 'active')
  .map((s) => `${s.name} (${studentSection(s.id)})`).join(', '));
const discount = computed(() => (groupBy('student_discounts', 'student_id').get(props.id) ?? [])
  .map((d) => `${d.percent ? d.percent + '%' : 'Rs ' + d.fixed_amount} ${d.reason ?? ''}`.trim()).join(', '));

const monthRows = computed(() => (groupBy('student_attendance', 'student_id').get(props.id) ?? []).filter((r) => String(r.date).startsWith(period())));
const att = computed(() => {
  const c = { present: 0, late: 0, absent: 0, leave: 0 } as Record<string, number>;
  for (const r of monthRows.value) c[r.status] = (c[r.status] ?? 0) + 1;
  return { ...c, pct: percent(c.present + c.late, monthRows.value.length) } as { present: number; late: number; absent: number; leave: number; pct: number };
});
const days = computed(() => {
  const by = new Map(monthRows.value.map((r) => [r.date, r.status]));
  const out: { date: string; status: string }[] = [];
  for (let d = 1; d <= daysInMonth(period()); d++) {
    const date = `${period()}-${String(d).padStart(2, '0')}`;
    if (date > today()) break;
    out.push({ date, status: by.get(date) ?? 'none' });
  }
  return out;
});
const results = computed(() => all('exams').map((exam) => ({ exam, ...examResult(exam.id, props.id) })).filter((r) => r.subjects > 0)
  .sort((a, b) => String(b.exam.starts_on).localeCompare(String(a.exam.starts_on))));

const modal = ref<'' | 'edit' | 'move'>('');
const error = ref('');
const form = reactive({ name: '', gender: 'male', date_of_birth: '', b_form: '', notes: '' });
function openEdit() {
  const s = student.value!;
  Object.assign(form, { name: s.name, gender: s.gender ?? 'male', date_of_birth: s.date_of_birth ?? '', b_form: s.b_form ?? '', notes: s.notes ?? '' });
  error.value = ''; modal.value = 'edit';
}
function save() {
  if (form.b_form && !CNIC_RE.test(form.b_form)) { error.value = 'B-Form must look like 12345-1234567-1.'; return; }
  update('students', props.id, { name: form.name.trim(), gender: form.gender, date_of_birth: form.date_of_birth || null, b_form: form.b_form || null, notes: form.notes || null });
  modal.value = ''; toast('Student saved.');
}
const moveTo = ref('');
function openMove() { moveTo.value = enrollment.value?.section_id ?? sortedSections()[0]?.id ?? ''; modal.value = 'move'; }
function move() {
  const e = enrollment.value;
  if (e?.section_id === moveTo.value) { modal.value = ''; return; }
  const roll = nextRoll(moveTo.value);
  if (e) update('enrollments', e.id, { section_id: moveTo.value, roll_no: roll, status: 'active' });
  else insert('enrollments', { student_id: props.id, academic_session_id: find('sections', moveTo.value)?.academic_session_id, section_id: moveTo.value, roll_no: roll, status: 'active' });
  modal.value = ''; toast(`Moved to ${sectionLabel(moveTo.value)}.`);
}
async function leave() {
  if (!(await ask('Mark as left?', `${student.value?.name} will stop getting fee vouchers and will not appear in attendance.`, 'Mark as left', true))) return;
  update('students', props.id, { status: 'left', left_on: today() });
  toast('Student marked as left.');
}
function rejoin() { update('students', props.id, { status: 'active', left_on: null }); toast('Student re-activated.'); }
</script>

<style scoped>
.head { display: flex; gap: 14px; align-items: center; }
.head h2 { margin: 0; font-size: 1.2rem; }
.head p { margin: 2px 0 0; }
.avatar.big { width: 56px; height: 56px; font-size: 1.1rem; }
.acts { margin-bottom: 14px; }
.stats.mini { grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); }
.stats.mini .stat { padding: 10px 12px; }
.stats.mini b { font-size: 1.15rem; }
.days { display: flex; flex-wrap: wrap; gap: 4px; }
.day { width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; font-size: 0.75rem; font-weight: 600; background: var(--ilm-soft); color: var(--ilm-muted); }
.day.present { background: var(--ilm-ok-bg); color: var(--ilm-ok); }
.day.late { background: var(--ilm-warn-bg); color: var(--ilm-warn); }
.day.absent { background: var(--ilm-bad-bg); color: var(--ilm-bad); }
.day.leave { background: var(--ilm-info-bg); color: var(--ilm-info); }
</style>
