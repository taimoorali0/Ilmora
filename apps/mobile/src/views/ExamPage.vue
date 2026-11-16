<template>
  <ion-page>
    <PageHeader :title="exam?.name ?? 'Exam'" back="/exams" />
    <ion-content>
      <div class="wrap" v-if="exam">
        <div class="row spread" style="margin-bottom:12px">
          <div class="seg">
            <button :class="{ on: tab === 'marks' }" @click="tab = 'marks'">Marks entry</button>
            <button :class="{ on: tab === 'result' }" @click="tab = 'result'">Class result</button>
          </div>
          <div class="row">
            <span class="pill" :class="exam.status === 'published' ? 'ok' : 'warn'">{{ exam.status === 'published' ? 'Results published' : 'Marks entry open' }}</span>
            <button v-if="canPublish && exam.status !== 'published'" class="btn primary sm" @click="publish">Publish results</button>
            <button v-if="canPublish && exam.status === 'published'" class="btn sm" @click="reopen">Reopen marks entry</button>
          </div>
        </div>

        <div class="row filters">
          <label class="field grow"><span>Class</span><select v-model="sectionId"><option v-for="s in sections" :key="s.id" :value="s.id">{{ sectionLabel(s.id) }}</option></select></label>
          <label v-if="tab === 'marks'" class="field grow"><span>Subject</span><select v-model="subjectRowId"><option v-for="es in subjects" :key="es.id" :value="es.id">{{ subjectName(es.subject_id) }} ({{ es.max_marks }})</option></select></label>
        </div>

        <template v-if="tab === 'marks'">
          <div v-if="locked" class="notice">Results are published, so marks are locked. Reopen marks entry to change them.</div>
          <p v-if="!subjects.length" class="empty">This class has no papers in this exam.</p>
          <div v-else class="card flush">
            <div class="row spread pad">
              <span class="muted">Paper date {{ nice(subjectRow?.paper_date) }} · total {{ subjectRow?.max_marks }} · pass {{ subjectRow?.pass_marks }}</span>
              <span class="muted">{{ entered }}/{{ students.length }} entered · class average {{ average }}%</span>
            </div>
            <div class="tbl-wrap">
              <table class="tbl">
                <thead><tr><th>Roll</th><th>Student</th><th class="right">Marks</th><th class="center">Absent</th><th class="center">Grade</th></tr></thead>
                <tbody>
                  <tr v-for="s in students" :key="s.id">
                    <td>{{ s.roll_no }}</td><td>{{ s.name }}</td>
                    <td class="right"><input class="input mark" type="number" inputmode="numeric" min="0" :max="subjectRow?.max_marks" :value="s.mark?.absent ? '' : s.mark?.obtained ?? ''" :disabled="locked || !!s.mark?.absent" :aria-label="`Marks for ${s.name}`" @change="setMark(s.id, ($event.target as HTMLInputElement))" /></td>
                    <td class="center"><input type="checkbox" class="chk" :checked="!!s.mark?.absent" :disabled="locked" :aria-label="`${s.name} absent`" @change="setAbsent(s.id, ($event.target as HTMLInputElement).checked)" /></td>
                    <td class="center">{{ s.mark && !s.mark.absent && s.mark.obtained != null ? grade(percent(s.mark.obtained, subjectRow?.max_marks ?? 100)) : '–' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-if="!students.length" class="empty">No students in this class.</p>
          </div>
        </template>

        <div v-else class="card flush">
          <div class="row spread pad">
            <span class="muted">{{ results.length }} students · {{ results.filter((r) => r.result.passed).length }} passed</span>
            <button class="btn wa sm" :disabled="!results.length" @click="sendResults"><ion-icon :icon="logoWhatsapp" /> Send results to parents</button>
          </div>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>Pos.</th><th>Roll</th><th>Student</th><th v-for="es in subjects" :key="es.id" class="center">{{ subjectName(es.subject_id).slice(0, 4) }}</th><th class="right">Total</th><th class="center">%</th><th class="center">Grade</th></tr></thead>
              <tbody>
                <tr v-for="r in results" :key="r.student.id" class="click" @click="$router.push(`/result/${exam.id}/${r.student.id}`)">
                  <td><b>{{ r.position ?? '–' }}</b></td><td>{{ r.student.roll_no }}</td><td class="nowrap">{{ r.student.name }}</td>
                  <td v-for="es in subjects" :key="es.id" class="center num" :class="{ failed: r.bySubject.get(es.subject_id)?.passed === false }">{{ cell(r.bySubject.get(es.subject_id)) }}</td>
                  <td class="right num">{{ r.result.obtained }}/{{ r.result.total }}</td><td class="center num">{{ r.result.pct }}</td>
                  <td class="center"><span class="pill" :class="r.result.passed ? 'ok' : 'bad'">{{ r.result.passed ? r.result.grade : 'Fail' }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="!results.length" class="empty">No marks entered for this class yet.</p>
        </div>
      </div>
      <p v-else class="empty">This exam is not on this device.</p>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { logoWhatsapp } from 'ionicons/icons';
import { computed, ref, watch } from 'vue';
import { grade, nice, percent } from '@/lib/format';
import { classPositions, examResult, examSubjectsFor, markOf, type SubjectResult } from '@/lib/logic/exams';
import { queueMessage, templates } from '@/lib/logic/messages';
import { mySections, sectionLabel, sectionStudents, subjectName } from '@/lib/school';
import { canWrite, hasRole } from '@/lib/session';
import { find, mix, update, upsert } from '@/lib/store';
import { ask, toast } from '@/lib/ui';

const props = defineProps<{ id: string }>();
const exam = computed(() => find('exams', props.id));
const tab = ref<'marks' | 'result'>('marks');
const sections = computed(() => mySections());
const sectionId = ref('');
const subjectRowId = ref('');
watch(sections, (s) => { if (!s.some((x) => x.id === sectionId.value)) sectionId.value = s[0]?.id ?? ''; }, { immediate: true });
const subjects = computed(() => examSubjectsFor(props.id, find('sections', sectionId.value)?.class_id).sort((a, b) => String(a.paper_date).localeCompare(String(b.paper_date))));
watch(subjects, (s) => { if (!s.some((x) => x.id === subjectRowId.value)) subjectRowId.value = s[0]?.id ?? ''; }, { immediate: true });
const subjectRow = computed(() => subjects.value.find((s) => s.id === subjectRowId.value));
const locked = computed(() => exam.value?.status === 'published' || !canWrite());
const canPublish = computed(() => hasRole('owner', 'principal', 'examiner') && canWrite());

const students = computed(() => sectionStudents(sectionId.value).map((s) => mix(s, { mark: subjectRowId.value ? markOf(subjectRowId.value, s.id) : undefined })));
const entered = computed(() => students.value.filter((s) => s.mark).length);
const average = computed(() => {
  const got = students.value.filter((s) => s.mark && !s.mark.absent && s.mark.obtained != null);
  return got.length ? Math.round(got.reduce((t, s) => t + percent(s.mark!.obtained, subjectRow.value?.max_marks ?? 100), 0) / got.length) : 0;
});

function setMark(studentId: string, input: HTMLInputElement) {
  const max = subjectRow.value?.max_marks ?? 100;
  if (input.value === '') return;
  const n = Math.round(Number(input.value));
  if (!Number.isFinite(n) || n < 0 || n > max) { toast(`Marks must be between 0 and ${max}.`, 'bad'); input.value = ''; return; }
  upsert('marks', { exam_subject_id: subjectRowId.value, student_id: studentId }, { obtained: n, absent: false });
}
function setAbsent(studentId: string, absent: boolean) {
  upsert('marks', { exam_subject_id: subjectRowId.value, student_id: studentId }, absent ? { obtained: null, absent: true } : { obtained: 0, absent: false });
}

const results = computed(() => {
  const pos = classPositions(props.id, sectionId.value);
  return sectionStudents(sectionId.value).map((student) => {
    const result = examResult(props.id, student.id);
    return { student, result, position: pos.get(student.id), bySubject: new Map(result.lines.map((l) => [l.subject_id, l])) };
  }).filter((r) => r.result.subjects > 0).sort((a, b) => (a.position ?? 999) - (b.position ?? 999));
});
const cell = (l?: SubjectResult) => (!l ? '–' : l.absent ? 'Abs' : String(l.obtained));

async function publish() {
  if (!(await ask('Publish results?', 'Marks are locked and parents can see the result cards in the parent app.', 'Publish'))) return;
  update('exams', props.id, { status: 'published' });
  toast('Results published.');
}
function reopen() { update('exams', props.id, { status: 'marks_entry' }); toast('Marks entry reopened.'); }
function sendResults() {
  let n = 0;
  for (const r of results.value) {
    const family = find('families', r.student.family_id);
    if (family && queueMessage(family.id, 'result', templates.result(family, r.student, exam.value!.name, r.result.obtained, r.result.total, r.result.pct))) n++;
  }
  toast(`${n} result messages queued for ${sectionLabel(sectionId.value)}.`);
}
</script>

<style scoped>
.filters { margin-bottom: 14px; }
.pad { padding: 12px 16px; }
.mark { width: 84px; text-align: right; display: inline-block; }
.chk { width: 22px; height: 22px; accent-color: var(--ilm-teal); }
.failed { color: var(--ilm-bad); font-weight: 700; }
</style>
