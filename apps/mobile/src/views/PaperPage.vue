<template>
  <ion-page>
    <PageHeader title="Exam paper" back="/papers" />
    <ion-content>
      <div class="wrap" v-if="paper">
        <div class="card no-print">
          <div class="steps">
            <div v-for="(s, i) in PAPER_STEPS" :key="s" class="step" :class="{ done: stepIndex > i, on: stepIndex === i }"><i>{{ i + 1 }}</i><span>{{ ['Teacher writes', 'Examiner reviews', 'Principal approves', 'Print'][i] }}</span></div>
          </div>
          <div class="row spread" style="margin-top:14px">
            <div><span class="pill" :class="PAPER_STATUS[paper.status]?.kind">{{ PAPER_STATUS[paper.status]?.label }}</span> <span class="muted">{{ PAPER_STATUS[paper.status]?.next }}</span></div>
            <div class="row">
              <button v-if="canEdit" class="btn" @click="openEdit">Edit</button>
              <button v-if="canEdit" class="btn primary" @click="move('submitted', 'Sent to the examiner.')">Submit to examiner</button>
              <button v-if="canReview" class="btn danger" @click="openReturn">Return to teacher</button>
              <button v-if="canReview" class="btn primary" @click="move('reviewed', 'Sent to the principal.', 'Checked by the examiner.')">Mark as reviewed</button>
              <button v-if="canApprove" class="btn danger" @click="openReturn">Return to teacher</button>
              <button v-if="canApprove" class="btn primary" @click="move('approved', 'Paper approved.', 'Approved for printing.')">Approve</button>
              <button v-if="paper.status === 'approved'" class="btn primary" @click="printPage"><ion-icon :icon="printOutline" /> Print paper</button>
            </div>
          </div>
          <p v-if="paper.review_note" class="notice" :class="{ warn: paper.status === 'returned' }" style="margin:14px 0 0"><b>Note:</b> {{ paper.review_note }}</p>
        </div>

        <div class="sheet">
          <Letterhead />
          <h3 class="doc">{{ paper.title }}</h3>
          <div class="row spread meta"><span>Total marks: {{ paper.total_marks }}</span><span>Time: {{ paper.duration_minutes }} minutes</span><span>Name: ______________ Roll no: ____</span></div>
          <pre class="paper">{{ paper.content }}</pre>
        </div>
      </div>
      <p v-else class="empty">This paper is not on this device.</p>

      <AppModal :open="modal === 'edit'" title="Edit paper" wide @close="modal = ''">
        <form class="form" @submit.prevent="saveEdit">
          <label class="field"><span>Total marks</span><input v-model.number="form.total_marks" type="number" min="5" max="200" /></label>
          <label class="field"><span>Duration (minutes)</span><input v-model.number="form.duration_minutes" type="number" min="10" max="300" /></label>
          <label class="field wide"><span>Paper *</span><textarea v-model="form.content" rows="14" required></textarea></label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = ''">Cancel</button><button class="btn primary" type="submit">Save</button></div>
        </form>
      </AppModal>
      <AppModal :open="modal === 'return'" title="Return to teacher" @close="modal = ''">
        <form class="form" @submit.prevent="sendBack">
          <label class="field wide"><span>What should be changed? *</span><textarea v-model="note" required maxlength="500"></textarea></label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = ''">Cancel</button><button class="btn primary" type="submit">Return paper</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { printOutline } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { PAPER_STATUS, PAPER_STEPS } from '@/lib/logic/papers';
import { canWrite, hasRole, session } from '@/lib/session';
import { find, update } from '@/lib/store';
import { printPage, toast } from '@/lib/ui';

const props = defineProps<{ id: string }>();
const paper = computed(() => find('exam_papers', props.id));
const isAuthor = computed(() => paper.value?.author_id === session.data?.user.id);
const stepIndex = computed(() => (paper.value?.status === 'returned' ? 0 : paper.value?.status === 'approved' ? 4 : PAPER_STEPS.indexOf(paper.value?.status ?? 'draft')));
const canEdit = computed(() => canWrite() && isAuthor.value && ['draft', 'returned'].includes(paper.value?.status ?? ''));
const canReview = computed(() => canWrite() && hasRole('examiner', 'owner') && paper.value?.status === 'submitted');
const canApprove = computed(() => canWrite() && hasRole('principal', 'owner') && paper.value?.status === 'reviewed');

const modal = ref<'' | 'edit' | 'return'>('');
const form = reactive({ total_marks: 100, duration_minutes: 120, content: '' });
const note = ref('');
function openEdit() { Object.assign(form, { total_marks: paper.value!.total_marks, duration_minutes: paper.value!.duration_minutes, content: paper.value!.content }); modal.value = 'edit'; }
function saveEdit() { update('exam_papers', props.id, { ...form }); modal.value = ''; toast('Paper saved.'); }
function move(status: string, done: string, reviewNote?: string) {
  update('exam_papers', props.id, reviewNote ? { status, review_note: reviewNote } : { status });
  toast(done);
}
function openReturn() { note.value = ''; modal.value = 'return'; }
function sendBack() { update('exam_papers', props.id, { status: 'returned', review_note: note.value.trim() }); modal.value = ''; toast('Paper returned to the teacher.'); }
</script>

<style scoped>
.steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.step { display: flex; flex-direction: column; align-items: center; gap: 6px; text-align: center; font-size: 0.78rem; color: var(--ilm-muted); }
.step i { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; font-style: normal; font-weight: 700; background: var(--ilm-soft); }
.step.done i { background: var(--ilm-teal); color: #fff; }
.step.on i { background: var(--ilm-blue); color: #fff; }
.step.on { color: var(--ion-text-color); font-weight: 600; }
.meta { font-size: 0.85rem; border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 14px; }
</style>
