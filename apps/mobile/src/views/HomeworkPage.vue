<template>
  <ion-page>
    <PageHeader title="Homework & worksheets" />
    <ion-content>
      <div class="wrap">
        <div class="row filters">
          <label class="field grow"><span>Class</span><select v-model="sectionId"><option value="">All my classes</option><option v-for="s in sections" :key="s.id" :value="s.id">{{ sectionLabel(s.id) }}</option></select></label>
          <button v-if="canEdit" class="btn primary" @click="open"><ion-icon :icon="add" /> New homework</button>
        </div>
        <div class="card flush">
          <div class="list">
            <div v-for="h in rows" :key="h.id" class="item top">
              <span class="pill" :class="KIND[h.kind]?.kind">{{ KIND[h.kind]?.label ?? h.kind }}</span>
              <div class="grow">
                <h4>{{ h.title }}</h4>
                <p>{{ sectionLabel(h.section_id) }} · {{ subjectName(h.subject_id) }} · given {{ nice(h.assigned_on) }}{{ h.due_on ? ' · due ' + nice(h.due_on) : '' }}</p>
                <p style="color:inherit">{{ h.body }}</p>
              </div>
              <button v-if="canEdit" class="btn wa sm" @click="send(h)" :aria-label="`Send ${h.title} to parents`"><ion-icon :icon="logoWhatsapp" /> Send to parents</button>
            </div>
            <p v-if="!rows.length" class="empty">No homework yet.</p>
          </div>
        </div>
      </div>
      <AppModal :open="modal" title="New homework" @close="modal = false">
        <form class="form" @submit.prevent="save">
          <label class="field"><span>Class *</span><select v-model="form.section_id" required><option v-for="s in sections" :key="s.id" :value="s.id">{{ sectionLabel(s.id) }}</option></select></label>
          <label class="field"><span>Subject *</span><select v-model="form.subject_id" required><option v-for="s in all('subjects')" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
          <label class="field"><span>Type</span><select v-model="form.kind"><option value="homework">Homework</option><option value="worksheet">Worksheet</option><option value="diary">Diary note</option></select></label>
          <label class="field"><span>Due date</span><input v-model="form.due_on" type="date" /></label>
          <label class="field wide"><span>Title *</span><input v-model="form.title" maxlength="150" required /></label>
          <label class="field wide"><span>Details</span><textarea v-model="form.body" maxlength="2000"></textarea></label>
          <label class="row wide"><input type="checkbox" v-model="form.notify" style="width:20px;height:20px" /> Send to all parents of this class on WhatsApp</label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = false">Cancel</button><button class="btn primary" type="submit">Save</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { add, logoWhatsapp } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { addDays, nice, today } from '@/lib/format';
import { queueMessage, templates } from '@/lib/logic/messages';
import { mySections, sectionLabel, sectionStudents, subjectName } from '@/lib/school';
import { canWrite, hasRole } from '@/lib/session';
import { all, find, insert, type Row } from '@/lib/store';
import { toast } from '@/lib/ui';

const KIND: Record<string, { label: string; kind: string }> = { homework: { label: 'Homework', kind: 'info' }, worksheet: { label: 'Worksheet', kind: 'ok' }, diary: { label: 'Diary', kind: 'warn' } };
const canEdit = computed(() => hasRole('owner', 'principal', 'admin', 'teacher') && canWrite());
const sections = computed(() => mySections());
const sectionId = ref('');
const rows = computed(() => {
  const ids = new Set(sections.value.map((s) => s.id));
  return all('homework').filter((h) => (sectionId.value ? h.section_id === sectionId.value : ids.has(h.section_id)))
    .sort((a, b) => String(b.assigned_on).localeCompare(String(a.assigned_on)) || String(b.created_at).localeCompare(String(a.created_at)));
});

function send(h: Row): number {
  const families = new Set(sectionStudents(h.section_id).map((s) => s.family_id));
  let n = 0;
  for (const id of families) {
    const family = find('families', id);
    if (family && queueMessage(family.id, 'homework', templates.homework(family, h.title, h.due_on))) n++;
  }
  toast(`Sent to ${n} families of ${sectionLabel(h.section_id)}.`);
  return n;
}

const modal = ref(false);
const form = reactive({ section_id: '', subject_id: '', kind: 'homework', due_on: '', title: '', body: '', notify: true });
function open() {
  Object.assign(form, { section_id: sectionId.value || sections.value[0]?.id || '', subject_id: all('subjects')[0]?.id ?? '', kind: 'homework', due_on: addDays(today(), 1), title: '', body: '', notify: true });
  modal.value = true;
}
function save() {
  const h = insert('homework', { section_id: form.section_id, subject_id: form.subject_id, kind: form.kind, title: form.title.trim(), body: form.body.trim(), assigned_on: today(), due_on: form.due_on || null });
  modal.value = false;
  if (form.notify) send(h); else toast('Homework saved.');
}
</script>

<style scoped>
.filters { margin-bottom: 14px; align-items: flex-end; }
.item.top { align-items: flex-start; }
</style>
