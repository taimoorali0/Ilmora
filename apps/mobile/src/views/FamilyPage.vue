<template>
  <ion-page>
    <PageHeader :title="family ? family.primary_guardian_name : 'Family'" back="/families" />
    <ion-content>
      <div class="wrap" v-if="family">
        <div class="stats">
          <div class="stat"><b>{{ money(ledger.invoiced) }}</b><span>Total invoiced</span></div>
          <div class="stat"><b>{{ money(ledger.paid) }}</b><span>Total paid</span></div>
          <div class="stat"><b :style="{ color: ledger.balance > 0 ? 'var(--ilm-bad)' : 'var(--ilm-ok)' }">{{ money(ledger.balance) }}</b><span>{{ ledger.balance < 0 ? 'Advance' : 'Balance due' }}</span><small v-if="ledger.overdue > 0">{{ money(ledger.overdue) }} overdue</small></div>
        </div>

        <div class="row acts">
          <button v-if="canCollect" class="btn primary" @click="openPay"><ion-icon :icon="cashOutline" /> Collect fee</button>
          <a v-if="ledger.balance > 0" class="btn wa" :href="waLink(phone, templates.lateFee(family))" target="_blank" rel="noopener" @click="logReminder"><ion-icon :icon="logoWhatsapp" /> Fee reminder</a>
          <button v-if="canEdit" class="btn" @click="openEdit">Edit family</button>
          <button v-if="canEdit" class="btn" @click="openChild"><ion-icon :icon="personAdd" /> Add child</button>
        </div>

        <div class="cols">
          <div class="card">
            <h3>Family <span class="muted">· {{ family.code }}</span></h3>
            <dl class="kv">
              <dt>Guardian</dt><dd>{{ family.primary_guardian_name }}</dd>
              <dt>CNIC</dt><dd>{{ family.primary_guardian_cnic || '–' }}</dd>
              <dt>Phone</dt><dd>{{ family.primary_phone }}</dd>
              <dt>WhatsApp</dt><dd>{{ family.whatsapp_phone || family.primary_phone }}</dd>
              <dt>Address</dt><dd>{{ family.address || '–' }}</dd>
              <dt>Parent app</dt><dd>{{ portal ? `Login ${portal.phone}` : 'No login yet' }}</dd>
            </dl>
          </div>
          <div class="card flush">
            <h3>Children ({{ children.length }})</h3>
            <div class="list">
              <div v-for="(s, i) in children" :key="s.id" class="item click" @click="$router.push(`/students/${s.id}`)">
                <span class="avatar">{{ initials(s.name) }}</span>
                <div class="grow"><h4>{{ s.name }}</h4><p>{{ s.admission_no }} · {{ studentSection(s.id) || 'Not enrolled' }}</p></div>
                <span v-if="siblingPct(i) && s.status === 'active'" class="pill info">{{ siblingPct(i) }}% sibling discount</span>
                <span v-if="s.status !== 'active'" class="pill">{{ s.status }}</span>
              </div>
              <p v-if="!children.length" class="empty">No children yet.</p>
            </div>
          </div>
        </div>

        <div class="card flush">
          <h3>Fee vouchers</h3>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>Month</th><th>Voucher</th><th>Due date</th><th class="right">Amount</th><th class="right">Paid</th><th class="right">Due</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="s in [...ledger.invoices].reverse()" :key="s.invoice.id" class="click" @click="$router.push(`/voucher/${s.invoice.id}`)">
                  <td>{{ niceMonth(s.invoice.period) }}</td><td class="nowrap">{{ s.invoice.number }}</td><td class="nowrap">{{ nice(s.invoice.due_date) }}</td>
                  <td class="right num">{{ money(s.total) }}</td><td class="right num">{{ money(s.paid) }}</td><td class="right num">{{ money(s.due) }}</td>
                  <td><span class="pill" :class="s.status === 'paid' ? 'ok' : s.overdue ? 'bad' : 'warn'">{{ s.status === 'paid' ? 'Paid' : s.overdue ? 'Overdue' : s.status === 'partial' ? 'Partly paid' : 'Unpaid' }}</span></td>
                </tr>
              </tbody>
            </table>
            <p v-if="!ledger.invoices.length" class="empty">No vouchers yet. Run the monthly vouchers from Fees.</p>
          </div>
        </div>

        <div class="card flush">
          <h3>Payments</h3>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>Date</th><th>Receipt</th><th>Method</th><th class="right">Amount</th><th></th></tr></thead>
              <tbody>
                <tr v-for="p in payments" :key="p.id" class="click" @click="$router.push(`/receipt/${p.id}`)">
                  <td class="nowrap">{{ nice(p.received_at) }}</td><td class="nowrap">{{ p.receipt_no }}</td><td>{{ p.method }}</td><td class="right num">{{ money(p.amount) }}</td>
                  <td><span v-if="p.reversed" class="pill bad">Reversed</span></td>
                </tr>
              </tbody>
            </table>
            <p v-if="!payments.length" class="empty">No payments yet.</p>
          </div>
        </div>
      </div>
      <p v-else class="empty">This family is not on this device.</p>

      <AppModal :open="modal === 'pay'" title="Collect fee" @close="modal = ''">
        <form class="form" @submit.prevent="pay">
          <p class="wide muted">Balance due {{ money(ledger.balance) }}. Works offline; the receipt number belongs to this device.</p>
          <label class="field"><span>Amount (Rs) *</span><input v-model.number="payForm.amount" type="number" min="1" step="1" required /></label>
          <label class="field"><span>Method</span><select v-model="payForm.method"><option value="cash">Cash</option><option value="bank">Bank</option><option value="jazzcash">JazzCash</option><option value="easypaisa">Easypaisa</option><option value="cheque">Cheque</option></select></label>
          <label class="field wide"><span>Reference (slip or transaction no)</span><input v-model="payForm.reference" maxlength="60" /></label>
          <p v-if="error" class="error wide" role="alert">{{ error }}</p>
          <div class="actions wide"><button type="button" class="btn" @click="modal = ''">Cancel</button><button class="btn primary" type="submit">Save and show receipt</button></div>
        </form>
      </AppModal>

      <AppModal :open="modal === 'edit'" title="Edit family" @close="modal = ''">
        <form class="form" @submit.prevent="saveFamily">
          <label class="field"><span>Father / guardian name *</span><input v-model="edit.primary_guardian_name" maxlength="100" required /></label>
          <label class="field"><span>CNIC</span><input :value="edit.primary_guardian_cnic" @input="edit.primary_guardian_cnic = formatCnic(($event.target as HTMLInputElement).value)" placeholder="12345-1234567-1" /></label>
          <label class="field"><span>Phone *</span><input v-model="edit.primary_phone" inputmode="tel" required /></label>
          <label class="field"><span>WhatsApp</span><input v-model="edit.whatsapp_phone" inputmode="tel" /></label>
          <label class="field wide"><span>Address</span><input v-model="edit.address" maxlength="300" /></label>
          <p v-if="error" class="error wide" role="alert">{{ error }}</p>
          <div class="actions wide"><button type="button" class="btn" @click="modal = ''">Cancel</button><button class="btn primary" type="submit">Save</button></div>
        </form>
      </AppModal>

      <AppModal :open="modal === 'child'" title="Add child" @close="modal = ''">
        <form class="form" @submit.prevent="saveChild">
          <label class="field"><span>Student name *</span><input v-model="child.name" maxlength="100" required /></label>
          <label class="field"><span>Gender</span><select v-model="child.gender"><option value="male">Boy</option><option value="female">Girl</option></select></label>
          <label class="field"><span>Date of birth</span><input v-model="child.dob" type="date" /></label>
          <label class="field"><span>Class *</span><select v-model="child.section_id" required><option v-for="s in sortedSections()" :key="s.id" :value="s.id">{{ sectionLabel(s.id) }}</option></select></label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = ''">Cancel</button><button class="btn primary" type="submit">Save</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { cashOutline, logoWhatsapp, personAdd } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { CNIC_RE, formatCnic, money, nice, niceMonth, normalizePhone, PHONE_RE, today } from '@/lib/format';
import { collectPayment, familyLedger } from '@/lib/logic/fees';
import { queueMessage, templates, waLink } from '@/lib/logic/messages';
import { admitStudent } from '@/lib/records';
import { initials, isManager, sectionLabel, sortedSections, studentSection } from '@/lib/school';
import { canWrite, hasRole } from '@/lib/session';
import { all, find, groupBy, mix, raw, update } from '@/lib/store';
import { toast } from '@/lib/ui';

const props = defineProps<{ id: string }>();
const router = useRouter();
const family = computed(() => find('families', props.id));
const ledger = computed(() => familyLedger(props.id));
const phone = computed(() => family.value?.whatsapp_phone || family.value?.primary_phone || '');
const children = computed(() => [...(groupBy('students', 'family_id').get(props.id) ?? [])]
  .sort((a, b) => String(a.admission_date).localeCompare(String(b.admission_date)) || String(a.admission_no).localeCompare(String(b.admission_no))));
const reversed = computed(() => new Set(all('reversals').map((r) => r.target_id)));
const payments = computed(() => [...(groupBy('payments', 'family_id').get(props.id) ?? [])]
  .sort((a, b) => String(b.received_at).localeCompare(String(a.received_at))).map((p) => mix(p, { reversed: reversed.value.has(p.id) })));
const portal = computed(() => raw('users').find((u) => u.family_id === props.id));
const canEdit = computed(() => isManager() && canWrite());
const canCollect = computed(() => hasRole('owner', 'accountant') && canWrite());

const rule = computed(() => all('discount_rules').find((r) => r.kind === 'sibling' && r.active !== false));
function siblingPct(index: number): number {
  const map = typeof rule.value?.config === 'string' ? JSON.parse(rule.value.config) : rule.value?.config ?? {};
  const n = children.value.filter((c, i) => c.status === 'active' && i <= index).length;
  return map[String(n)] ?? (n >= 4 ? map['4+'] ?? 0 : 0);
}

const modal = ref<'' | 'pay' | 'edit' | 'child'>('');
const error = ref('');
const payForm = reactive({ amount: 0, method: 'cash', reference: '' });
function openPay() { Object.assign(payForm, { amount: Math.max(0, ledger.value.balance), method: 'cash', reference: '' }); error.value = ''; modal.value = 'pay'; }
function pay() {
  try {
    const p = collectPayment(props.id, Number(payForm.amount), payForm.method, payForm.reference);
    modal.value = '';
    toast(`Receipt ${p.receipt_no} saved.`);
    router.push(`/receipt/${p.id}`);
  } catch (e) { error.value = (e as Error).message; }
}

const edit = reactive({ primary_guardian_name: '', primary_guardian_cnic: '', primary_phone: '', whatsapp_phone: '', address: '' });
function openEdit() {
  const f = family.value!;
  Object.assign(edit, { primary_guardian_name: f.primary_guardian_name, primary_guardian_cnic: f.primary_guardian_cnic ?? '', primary_phone: f.primary_phone, whatsapp_phone: f.whatsapp_phone ?? '', address: f.address ?? '' });
  error.value = ''; modal.value = 'edit';
}
function saveFamily() {
  const p = normalizePhone(edit.primary_phone);
  if (!PHONE_RE.test(p)) { error.value = 'Phone must be a mobile number like 03001234567.'; return; }
  if (edit.primary_guardian_cnic && !CNIC_RE.test(edit.primary_guardian_cnic)) { error.value = 'CNIC must look like 12345-1234567-1.'; return; }
  update('families', props.id, { ...edit, primary_phone: p, whatsapp_phone: normalizePhone(edit.whatsapp_phone) || p, primary_guardian_cnic: edit.primary_guardian_cnic || null, address: edit.address || null });
  modal.value = ''; toast('Family saved.');
}

const child = reactive({ name: '', gender: 'male', dob: '', section_id: '' });
function openChild() { Object.assign(child, { name: '', gender: 'male', dob: '', section_id: sortedSections()[0]?.id ?? '' }); modal.value = 'child'; }
function saveChild() {
  const s = admitStudent(props.id, { name: child.name.trim(), gender: child.gender, date_of_birth: child.dob || null, admission_date: today() }, child.section_id);
  modal.value = ''; toast(`${s.name} admitted (${s.admission_no}).`);
}
function logReminder() {
  if (family.value) queueMessage(props.id, 'late_fee', templates.lateFee(family.value));
}
</script>

<style scoped>
.acts { margin-bottom: 14px; }
</style>
