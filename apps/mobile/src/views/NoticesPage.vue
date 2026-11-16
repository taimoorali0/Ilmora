<template>
  <ion-page>
    <PageHeader title="Notices" />
    <ion-content>
      <div class="wrap narrow">
        <div class="row spread" style="margin-bottom:12px">
          <p class="muted" style="margin:0">Notices appear on staff dashboards and in the parent app.</p>
          <button v-if="canEdit" class="btn primary" @click="open"><ion-icon :icon="add" /> New notice</button>
        </div>
        <div v-for="n in rows" :key="n.id" class="card">
          <div class="row spread"><h3 style="margin:0">{{ n.title }}</h3><span class="pill">{{ AUDIENCE[n.audience] ?? n.audience }}</span></div>
          <p class="muted" style="margin:4px 0 8px">{{ nice(n.published_on) }}</p>
          <p style="margin:0;line-height:1.5">{{ n.body }}</p>
          <button v-if="canEdit && n.audience !== 'staff'" class="btn wa sm" style="margin-top:12px" @click="send(n)"><ion-icon :icon="logoWhatsapp" /> Send to all parents</button>
        </div>
        <p v-if="!rows.length" class="empty">No notices yet.</p>
      </div>
      <AppModal :open="modal" title="New notice" @close="modal = false">
        <form class="form" @submit.prevent="save">
          <label class="field wide"><span>Title *</span><input v-model="form.title" maxlength="120" required /></label>
          <label class="field wide"><span>Notice *</span><textarea v-model="form.body" maxlength="2000" required></textarea></label>
          <label class="field"><span>For</span><select v-model="form.audience"><option value="all">Everyone</option><option value="parents">Parents</option><option value="staff">Staff</option></select></label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = false">Cancel</button><button class="btn primary" type="submit">Publish</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { add, logoWhatsapp } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { nice, today } from '@/lib/format';
import { queueMessage } from '@/lib/logic/messages';
import { isManager } from '@/lib/school';
import { canWrite, session } from '@/lib/session';
import { all, groupBy, insert, type Row } from '@/lib/store';
import { ask, toast } from '@/lib/ui';

const AUDIENCE: Record<string, string> = { all: 'Everyone', parents: 'Parents', staff: 'Staff' };
const canEdit = computed(() => isManager() && canWrite());
const rows = computed(() => [...all('notices')].sort((a, b) => String(b.published_on).localeCompare(String(a.published_on)) || String(b.created_at).localeCompare(String(a.created_at))));
const modal = ref(false);
const form = reactive({ title: '', body: '', audience: 'all' });
function open() { Object.assign(form, { title: '', body: '', audience: 'all' }); modal.value = true; }
function save() { insert('notices', { title: form.title.trim(), body: form.body.trim(), audience: form.audience, published_on: today() }); modal.value = false; toast('Notice published.'); }
async function send(n: Row) {
  const families = all('families').filter((f) => (groupBy('students', 'family_id').get(f.id) ?? []).some((s) => s.status === 'active'));
  if (!(await ask('Send to all parents?', `This queues ${families.length} WhatsApp messages.`, 'Send'))) return;
  let count = 0;
  for (const f of families) if (queueMessage(f.id, 'general', `Assalam-o-Alaikum ${f.primary_guardian_name}, ${n.title}: ${n.body} – ${session.data?.user.school?.name ?? ''}`)) count++;
  toast(`${count} messages queued.`);
}
</script>
