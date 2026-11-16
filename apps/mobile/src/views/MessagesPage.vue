<template>
  <ion-page>
    <PageHeader title="Parent messages" />
    <ion-content>
      <div class="wrap">
        <div class="stats">
          <div class="stat"><b>{{ count('sent') }}</b><span>Sent</span></div>
          <div class="stat"><b>{{ count('queued') }}</b><span>Waiting to send</span></div>
          <div class="stat"><b>{{ count('failed') }}</b><span>Failed</span></div>
        </div>
        <div class="notice" v-if="IS_DEMO">The demo records messages but does not send them. On your server they go out through the WhatsApp or SMS gateway you configure. The WhatsApp button always opens the message in WhatsApp for sending by hand.</div>

        <div class="card">
          <h3>Send a message</h3>
          <form class="form" @submit.prevent="send">
            <label class="field"><span>To</span>
              <select v-model="form.to">
                <option value="all">All parents ({{ audience('all').length }})</option>
                <option value="due">Parents with fee due ({{ audience('due').length }})</option>
                <option v-for="s in sortedSections()" :key="s.id" :value="s.id">{{ sectionLabel(s.id) }}</option>
              </select>
            </label>
            <label class="field"><span>Send by</span><select v-model="form.channel"><option value="whatsapp">WhatsApp</option><option value="sms">SMS</option></select></label>
            <label class="field wide"><span>Message *</span><textarea v-model="form.body" maxlength="600" required placeholder="Assalam-o-Alaikum, …"></textarea></label>
            <div class="actions wide"><span class="muted grow">{{ audience(form.to).length }} families · the school name is added at the end</span><button class="btn primary" type="submit" :disabled="!canSend">Queue message</button></div>
          </form>
        </div>

        <div class="row spread" style="margin-bottom:12px">
          <div class="seg">
            <button :class="{ on: kind === '' }" @click="kind = ''">All</button>
            <button v-for="(label, key) in KIND" :key="key" :class="{ on: kind === key }" @click="kind = String(key)">{{ label }}</button>
          </div>
        </div>
        <div class="card flush">
          <div class="list">
            <div v-for="m in rows" :key="m.id" class="item top">
              <span class="pill" :class="m.status === 'sent' ? 'ok' : m.status === 'failed' ? 'bad' : 'warn'">{{ m.status }}</span>
              <div class="grow"><h4>{{ m.to_name }} <span class="muted">· {{ m.to_phone }} · {{ m.channel }} · {{ KIND[m.kind] ?? m.kind }}</span></h4><p>{{ m.body }}</p><p>{{ nice(m.sent_at ?? m.created_at) }} {{ time(m.sent_at ?? m.created_at) }}{{ m.error ? ' · ' + m.error : '' }}</p></div>
              <a class="btn wa sm" :href="waLink(m.to_phone, m.body)" target="_blank" rel="noopener" :aria-label="`Open in WhatsApp for ${m.to_name}`"><ion-icon :icon="logoWhatsapp" /></a>
            </div>
            <p v-if="!rows.length" class="empty">No messages yet.</p>
          </div>
        </div>
        <p v-if="total > rows.length" class="muted center">Showing the latest {{ rows.length }} of {{ total }}.</p>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { logoWhatsapp } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { nice, time } from '@/lib/format';
import { defaulters } from '@/lib/logic/fees';
import { queueMessage, waLink } from '@/lib/logic/messages';
import { sectionLabel, sectionStudents, sortedSections } from '@/lib/school';
import { canWrite, IS_DEMO, session } from '@/lib/session';
import { all, find, groupBy, type Row } from '@/lib/store';
import { ask, toast } from '@/lib/ui';

const KIND: Record<string, string> = { late_fee: 'Fee reminder', absent: 'Absence', attendance_report: 'Attendance report', result: 'Result', homework: 'Homework', fee_voucher: 'Voucher', general: 'General' };
const kind = ref('');
const canSend = computed(() => canWrite());
const sorted = computed(() => [...all('messages')].sort((a, b) => String(b.sent_at ?? b.created_at).localeCompare(String(a.sent_at ?? a.created_at))));
const filtered = computed(() => sorted.value.filter((m) => !kind.value || m.kind === kind.value));
const rows = computed(() => filtered.value.slice(0, 100));
const total = computed(() => filtered.value.length);
const count = (status: string) => all('messages').filter((m) => m.status === status).length;

function audience(to: string): Row[] {
  if (to === 'due') return defaulters().map((d) => d.family);
  if (to === 'all') return all('families').filter((f) => (groupBy('students', 'family_id').get(f.id) ?? []).some((s) => s.status === 'active'));
  return [...new Set(sectionStudents(to).map((s) => s.family_id))].map((id) => find('families', id)).filter(Boolean) as Row[];
}
const form = reactive({ to: 'all', channel: 'whatsapp' as 'whatsapp' | 'sms', body: '' });
async function send() {
  const families = audience(form.to);
  if (!families.length) { toast('No families in this group.', 'bad'); return; }
  if (families.length > 20 && !(await ask('Send this message?', `It will be queued for ${families.length} families.`, 'Queue messages'))) return;
  let n = 0;
  for (const f of families) if (queueMessage(f.id, 'general', `${form.body.trim()} – ${session.data?.user.school?.name ?? ''}`, form.channel)) n++;
  form.body = '';
  toast(`${n} messages queued.`);
}
</script>

<style scoped>.item.top { align-items: flex-start; }</style>
