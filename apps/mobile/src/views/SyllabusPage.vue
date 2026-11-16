<template>
  <ion-page>
    <PageHeader title="Syllabus" />
    <ion-content>
      <div class="wrap">
        <div class="row filters">
          <label class="field grow"><span>Class</span><select v-model="classId"><option v-for="c in classes" :key="c.id" :value="c.id">{{ className(c.id) }}</option></select></label>
          <button v-if="canEdit" class="btn primary" @click="open"><ion-icon :icon="add" /> Add topic</button>
        </div>
        <div class="stats">
          <div class="stat"><b>{{ overall }}%</b><span>Covered in {{ className(classId) }}</span><small>{{ topics.filter((t) => t.status === 'done').length }} of {{ topics.length }} topics</small></div>
        </div>
        <p v-if="!groups.length" class="empty">No syllabus topics for this class yet.</p>
        <div class="cols">
          <div v-for="g in groups" :key="g.subjectId" class="card flush">
            <div class="pad">
              <div class="row spread"><h3 style="margin:0">{{ subjectName(g.subjectId) }}</h3><b>{{ g.pct }}%</b></div>
              <div class="bar" style="margin-top:8px"><i :style="{ width: g.pct + '%' }"></i></div>
            </div>
            <div class="list">
              <div v-for="t in g.topics" :key="t.id" class="item">
                <div class="grow"><h4>{{ t.title }}</h4><p v-if="t.completed_on">Completed {{ nice(t.completed_on) }}</p></div>
                <select class="input st" :value="t.status" :disabled="!canEdit" :aria-label="`Status of ${t.title}`" @change="setStatus(t.id, ($event.target as HTMLSelectElement).value)">
                  <option value="pending">Pending</option><option value="in_progress">In progress</option><option value="done">Done</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>
      <AppModal :open="modal" title="Add topic" @close="modal = false">
        <form class="form" @submit.prevent="save">
          <label class="field"><span>Subject *</span><select v-model="form.subject_id" required><option v-for="s in all('subjects')" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
          <label class="field wide"><span>Topic *</span><input v-model="form.title" maxlength="150" required /></label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = false">Cancel</button><button class="btn primary" type="submit">Save</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { add } from 'ionicons/icons';
import { computed, reactive, ref, watch } from 'vue';
import { nice, percent, today } from '@/lib/format';
import { className, mySections, sortedClasses, subjectName } from '@/lib/school';
import { canWrite, hasRole } from '@/lib/session';
import { all, insert, update, where } from '@/lib/store';
import { toast } from '@/lib/ui';

const canEdit = computed(() => hasRole('owner', 'principal', 'teacher') && canWrite());
const classes = computed(() => { const ids = new Set(mySections().map((s) => s.class_id)); return sortedClasses().filter((c) => ids.has(c.id)); });
const classId = ref('');
watch(classes, (c) => {
  if (c.some((x) => x.id === classId.value)) return;
  classId.value = c.find((x) => all('syllabus_topics').some((t) => t.class_id === x.id))?.id ?? c[0]?.id ?? '';
}, { immediate: true });
const topics = computed(() => where('syllabus_topics', { class_id: classId.value }));
const overall = computed(() => percent(topics.value.filter((t) => t.status === 'done').length, topics.value.length));
const groups = computed(() => {
  const m = new Map<string, typeof topics.value>();
  for (const t of [...topics.value].sort((a, b) => a.sort_order - b.sort_order)) m.set(t.subject_id, [...(m.get(t.subject_id) ?? []), t]);
  return [...m.entries()].map(([subjectId, list]) => ({ subjectId, topics: list, pct: percent(list.filter((t) => t.status === 'done').length, list.length) }));
});
function setStatus(id: string, status: string) { update('syllabus_topics', id, { status, completed_on: status === 'done' ? today() : null }); }

const modal = ref(false);
const form = reactive({ subject_id: '', title: '' });
function open() { Object.assign(form, { subject_id: all('subjects')[0]?.id ?? '', title: '' }); modal.value = true; }
function save() {
  const order = Math.max(0, ...topics.value.filter((t) => t.subject_id === form.subject_id).map((t) => t.sort_order)) + 1;
  insert('syllabus_topics', { class_id: classId.value, subject_id: form.subject_id, title: form.title.trim(), sort_order: order, status: 'pending', completed_on: null });
  modal.value = false; toast('Topic added.');
}
</script>

<style scoped>
.filters { margin-bottom: 14px; align-items: flex-end; }
.pad { padding: 14px 16px 10px; }
.st { width: 130px; flex: none; min-height: 36px; padding: 6px 8px; font-size: 0.85rem; }
</style>
