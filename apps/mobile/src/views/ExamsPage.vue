<template>
  <ion-page>
    <PageHeader title="Exams & results" />
    <ion-content>
      <div class="wrap">
        <div class="row spread" style="margin-bottom:12px">
          <p class="muted" style="margin:0">Session {{ currentSession()?.name }}</p>
          <button v-if="canEdit" class="btn primary" @click="open"><ion-icon :icon="add" /> New exam</button>
        </div>
        <div class="card flush">
          <div class="list">
            <div v-for="e in rows" :key="e.exam.id" class="item click" @click="$router.push(`/exams/${e.exam.id}`)">
              <div class="grow">
                <h4>{{ e.exam.name }}</h4>
                <p>{{ nice(e.exam.starts_on) }} to {{ nice(e.exam.ends_on) }} · {{ e.subjects }} papers</p>
                <div class="bar" style="margin-top:6px;max-width:320px"><i :style="{ width: e.pct + '%' }"></i></div>
                <p>Marks entered: {{ e.pct }}%</p>
              </div>
              <span class="pill" :class="STATUS[e.exam.status]?.kind">{{ STATUS[e.exam.status]?.label ?? e.exam.status }}</span>
            </div>
            <p v-if="!rows.length" class="empty">No exams yet.</p>
          </div>
        </div>
      </div>
      <AppModal :open="modal" title="New exam" @close="modal = false">
        <form class="form" @submit.prevent="save">
          <label class="field wide"><span>Exam name *</span><input v-model="form.name" maxlength="60" placeholder="e.g. Final Term" required /></label>
          <label class="field"><span>Starts on *</span><input v-model="form.starts_on" type="date" required /></label>
          <label class="field"><span>Ends on *</span><input v-model="form.ends_on" type="date" required /></label>
          <label class="field"><span>Total marks per paper</span><input v-model.number="form.max" type="number" min="10" max="200" /></label>
          <label class="field"><span>Pass marks</span><input v-model.number="form.pass" type="number" min="1" max="200" /></label>
          <p class="muted wide">A paper is created for every subject of every class. You can enter marks as soon as the exam is saved.</p>
          <div class="actions wide"><button type="button" class="btn" @click="modal = false">Cancel</button><button class="btn primary" type="submit">Create exam</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { add } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { addDays, nice, percent, today } from '@/lib/format';
import { examsSorted } from '@/lib/logic/exams';
import { currentSession, sectionStudents, sortedSections } from '@/lib/school';
import { canWrite, hasRole } from '@/lib/session';
import { all, groupBy, insert } from '@/lib/store';
import { toast } from '@/lib/ui';

const STATUS: Record<string, { label: string; kind: string }> = {
  draft: { label: 'Draft', kind: '' }, marks_entry: { label: 'Marks entry', kind: 'warn' }, published: { label: 'Results published', kind: 'ok' },
};
const canEdit = computed(() => hasRole('owner', 'principal', 'examiner') && canWrite());
const strength = computed(() => {
  const m = new Map<string, number>();
  for (const s of sortedSections()) m.set(s.class_id, (m.get(s.class_id) ?? 0) + sectionStudents(s.id).length);
  return m;
});
const rows = computed(() => examsSorted().map((exam) => {
  const subjects = groupBy('exam_subjects', 'exam_id').get(exam.id) ?? [];
  const expected = subjects.reduce((s, es) => s + (strength.value.get(es.class_id) ?? 0), 0);
  const entered = subjects.reduce((s, es) => s + (groupBy('marks', 'exam_subject_id').get(es.id)?.length ?? 0), 0);
  return { exam, subjects: subjects.length, pct: Math.min(100, percent(entered, expected)) };
}));

const modal = ref(false);
const form = reactive({ name: '', starts_on: today(), ends_on: addDays(today(), 10), max: 100, pass: 33 });
function open() { Object.assign(form, { name: '', starts_on: today(), ends_on: addDays(today(), 10), max: 100, pass: 33 }); modal.value = true; }
function save() {
  if (form.ends_on < form.starts_on) { toast('The end date is before the start date.', 'bad'); return; }
  const exam = insert('exams', { academic_session_id: currentSession()?.id, name: form.name.trim(), starts_on: form.starts_on, ends_on: form.ends_on, status: 'marks_entry' });
  const seen = new Set<string>();
  for (const sec of sortedSections()) {
    (groupBy('section_subjects', 'section_id').get(sec.id) ?? []).forEach((ss, i) => {
      const key = `${sec.class_id}:${ss.subject_id}`;
      if (seen.has(key)) return;
      seen.add(key);
      insert('exam_subjects', { exam_id: exam.id, class_id: sec.class_id, subject_id: ss.subject_id, max_marks: Number(form.max) || 100, pass_marks: Number(form.pass) || 33, paper_date: addDays(form.starts_on, i * 2) });
    });
  }
  modal.value = false;
  toast(seen.size ? `${exam.name} created with ${seen.size} papers.` : `${exam.name} created. Add subjects to classes to create papers.`);
}
void all;
</script>
