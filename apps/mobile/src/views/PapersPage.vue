<template>
  <ion-page>
    <PageHeader title="Exam papers" />
    <ion-content>
      <div class="wrap">
        <div class="notice">Papers move on screen, not on paper: the teacher writes, the examiner reviews, the principal approves, then it is printed.</div>
        <div class="row spread" style="margin-bottom:12px">
          <div class="seg">
            <button :class="{ on: filter === '' }" @click="filter = ''">All ({{ mine.length }})</button>
            <button v-for="(s, key) in PAPER_STATUS" :key="key" :class="{ on: filter === key }" @click="filter = String(key)">{{ s.label }} ({{ mine.filter((p) => p.status === key).length }})</button>
          </div>
          <button v-if="canWritePaper" class="btn primary" @click="open"><ion-icon :icon="add" /> New paper</button>
        </div>
        <div class="card flush">
          <div class="list">
            <div v-for="p in shown" :key="p.id" class="item click" @click="$router.push(`/papers/${p.id}`)">
              <div class="grow"><h4>{{ p.title }}</h4><p>{{ className(p.class_id) }} · {{ subjectName(p.subject_id) }} · {{ p.total_marks }} marks · by {{ author(p.author_id) }}</p><p>{{ PAPER_STATUS[p.status]?.next }}</p></div>
              <span class="pill" :class="PAPER_STATUS[p.status]?.kind">{{ PAPER_STATUS[p.status]?.label ?? p.status }}</span>
            </div>
            <p v-if="!shown.length" class="empty">No papers here.</p>
          </div>
        </div>
      </div>
      <AppModal :open="modal" title="New exam paper" wide @close="modal = false">
        <form class="form" @submit.prevent="save">
          <label class="field"><span>Exam *</span><select v-model="form.exam_id" required><option v-for="e in examsSorted()" :key="e.id" :value="e.id">{{ e.name }}</option></select></label>
          <label class="field"><span>Class *</span><select v-model="form.class_id" required><option v-for="c in sortedClasses()" :key="c.id" :value="c.id">{{ className(c.id) }}</option></select></label>
          <label class="field"><span>Subject *</span><select v-model="form.subject_id" required><option v-for="s in all('subjects')" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
          <label class="field"><span>Total marks</span><input v-model.number="form.total_marks" type="number" min="5" max="200" /></label>
          <label class="field"><span>Duration (minutes)</span><input v-model.number="form.duration_minutes" type="number" min="10" max="300" /></label>
          <label class="field wide"><span>Paper *</span><textarea v-model="form.content" rows="10" required placeholder="Section A – Objective&#10;Q1. …"></textarea></label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = false">Cancel</button><button class="btn primary" type="submit">Save as draft</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { add } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { examsSorted } from '@/lib/logic/exams';
import { PAPER_STATUS } from '@/lib/logic/papers';
import { className, sortedClasses, subjectName } from '@/lib/school';
import { canWrite, hasRole, session } from '@/lib/session';
import { all, find, insert, raw } from '@/lib/store';
import { toast } from '@/lib/ui';

const router = useRouter();
const filter = ref('');
const reviewer = computed(() => hasRole('owner', 'principal', 'examiner'));
const canWritePaper = computed(() => hasRole('teacher', 'examiner', 'owner', 'principal') && canWrite());
/** Teachers see their own papers; examiner and principal see every paper that has left draft. */
const mine = computed(() => all('exam_papers').filter((p) => p.author_id === session.data?.user.id || (reviewer.value && p.status !== 'draft'))
  .sort((a, b) => String(b.updated_at).localeCompare(String(a.updated_at))));
const shown = computed(() => mine.value.filter((p) => !filter.value || p.status === filter.value));
const author = (id: string) => raw('users').find((u) => u.id === id)?.name ?? 'Teacher';

const modal = ref(false);
const form = reactive({ exam_id: '', class_id: '', subject_id: '', total_marks: 100, duration_minutes: 120, content: '' });
function open() {
  Object.assign(form, { exam_id: examsSorted()[0]?.id ?? '', class_id: sortedClasses()[0]?.id ?? '', subject_id: all('subjects')[0]?.id ?? '', total_marks: 100, duration_minutes: 120, content: '' });
  if (!form.exam_id) { toast('Create an exam first.', 'bad'); return; }
  modal.value = true;
}
function save() {
  const title = `${find('exams', form.exam_id)?.name} – ${subjectName(form.subject_id)} – ${className(form.class_id)}`;
  const p = insert('exam_papers', { ...form, title, status: 'draft', author_id: session.data?.user.id, review_note: null });
  modal.value = false; toast('Draft saved.');
  router.push(`/papers/${p.id}`);
}
</script>
