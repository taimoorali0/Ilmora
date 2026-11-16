<template>
  <ion-page>
    <PageHeader title="Fees" />
    <ion-content>
      <div class="wrap">
        <div class="stats">
          <div class="stat"><b>{{ money(monthInvoiced) }}</b><span>Vouchers for {{ niceMonth(month) }}</span><small>{{ monthInvoices.length }} families</small></div>
          <div class="stat"><b>{{ money(monthCollected) }}</b><span>Collected in {{ niceMonth(month) }}</span><small>{{ monthPayments.length }} receipts</small></div>
          <div class="stat"><b>{{ money(outstanding) }}</b><span>Total outstanding</span><small>{{ owing.length }} families</small></div>
          <div class="stat"><b>{{ money(overdue) }}</b><span>Overdue</span><small>past the due date</small></div>
        </div>

        <div class="row spread" style="margin-bottom:12px">
          <div class="seg">
            <button v-for="t in TABS" :key="t.id" :class="{ on: tab === t.id }" @click="tab = t.id">{{ t.label }}</button>
          </div>
          <label class="field month"><span class="sr">Month</span><input v-model="month" type="month" aria-label="Month" /></label>
        </div>

        <template v-if="tab === 'collect'">
          <div class="card">
            <h3>Collect a fee</h3>
            <input class="input" v-model="search" type="search" placeholder="Search family, student, phone or family code" aria-label="Search family" />
            <div class="list results" v-if="search.trim()">
              <div v-for="f in found" :key="f.id" class="item click" @click="$router.push(`/families/${f.id}`)">
                <div class="grow"><h4>{{ f.primary_guardian_name }} <span class="muted">· {{ f.code }}</span></h4><p>{{ kids(f.id) }}</p></div>
                <span class="pill" :class="familyLedger(f.id).balance > 0 ? 'warn' : 'ok'">{{ familyLedger(f.id).balance > 0 ? money(familyLedger(f.id).balance) + ' due' : 'Clear' }}</span>
              </div>
              <p v-if="!found.length" class="empty">No family matches.</p>
            </div>
          </div>
          <div class="cols">
            <div class="card">
              <h3>Monthly vouchers</h3>
              <p class="muted">Creates one voucher per family for {{ niceMonth(month) }} with sibling discounts, scholarships and late fee. Families that already have one are skipped.</p>
              <button class="btn primary" :disabled="!canRun || busy" @click="run">{{ busy ? 'Creating…' : `Create vouchers for ${niceMonth(month)}` }}</button>
              <p v-if="!online" class="muted" style="margin-top:8px">Needs internet: vouchers are created on the server.</p>
            </div>
            <div class="card">
              <h3>Collection by method, {{ niceMonth(month) }}</h3>
              <div v-for="m in methods" :key="m.method" class="meth">
                <div class="row spread"><span>{{ m.method }}</span><b class="num">{{ money(m.amount) }}</b></div>
                <div class="bar"><i :style="{ width: percent(m.amount, monthCollected) + '%' }"></i></div>
              </div>
              <p v-if="!methods.length" class="muted">Nothing collected in this month yet.</p>
            </div>
          </div>
        </template>

        <div v-if="tab === 'due'" class="card flush">
          <div class="row spread pad">
            <h3 style="margin:0">Families that owe ({{ owing.length }})</h3>
            <button class="btn wa sm" :disabled="!owing.length" @click="remindAll"><ion-icon :icon="logoWhatsapp" /> Send reminder to all</button>
          </div>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>Family</th><th>Children</th><th>Phone</th><th class="right">Overdue</th><th class="right">Balance</th><th></th></tr></thead>
              <tbody>
                <tr v-for="d in owing.slice(0, 200)" :key="d.family.id" class="click" @click="$router.push(`/families/${d.family.id}`)">
                  <td><b>{{ d.family.primary_guardian_name }}</b><br /><span class="muted">{{ d.family.code }}</span></td>
                  <td>{{ kids(d.family.id) }}</td><td class="nowrap">{{ d.family.primary_phone }}</td>
                  <td class="right num">{{ d.ledger.overdue ? money(d.ledger.overdue) : '–' }}</td><td class="right num"><b>{{ money(d.ledger.balance) }}</b></td>
                  <td class="right"><a class="btn wa sm" :href="waLink(d.family.whatsapp_phone || d.family.primary_phone, templates.lateFee(d.family))" target="_blank" rel="noopener" @click.stop="remind(d.family)" :aria-label="`WhatsApp ${d.family.primary_guardian_name}`"><ion-icon :icon="logoWhatsapp" /></a></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="!owing.length" class="empty">No family owes anything.</p>
        </div>

        <div v-if="tab === 'vouchers'" class="card flush">
          <h3>Vouchers, {{ niceMonth(month) }}</h3>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>Voucher</th><th>Family</th><th>Due date</th><th class="right">Amount</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="v in monthInvoices.slice(0, 300)" :key="v.invoice.id" class="click" @click="$router.push(`/voucher/${v.invoice.id}`)">
                  <td class="nowrap">{{ v.invoice.number }}</td><td>{{ find('families', v.invoice.family_id)?.primary_guardian_name }}</td>
                  <td class="nowrap">{{ nice(v.invoice.due_date) }}</td><td class="right num">{{ money(v.total) }}</td>
                  <td><span class="pill" :class="v.status === 'paid' ? 'ok' : v.overdue ? 'bad' : 'warn'">{{ v.status === 'paid' ? 'Paid' : v.overdue ? 'Overdue' : v.status === 'partial' ? 'Partly paid' : 'Unpaid' }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="!monthInvoices.length" class="empty">No vouchers for this month. Create them from the Collect tab.</p>
        </div>

        <div v-if="tab === 'daybook'" class="card flush">
          <h3>Receipts, {{ niceMonth(month) }}</h3>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>Date</th><th>Receipt</th><th>Family</th><th>Method</th><th class="right">Amount</th></tr></thead>
              <tbody>
                <tr v-for="p in monthPayments.slice(0, 300)" :key="p.id" class="click" @click="$router.push(`/receipt/${p.id}`)">
                  <td class="nowrap">{{ nice(p.received_at) }}</td><td class="nowrap">{{ p.receipt_no }}</td><td>{{ find('families', p.family_id)?.primary_guardian_name }}</td><td>{{ p.method }}</td><td class="right num">{{ money(p.amount) }}</td>
                </tr>
              </tbody>
              <tfoot v-if="monthPayments.length"><tr><td colspan="4">Total</td><td class="right num">{{ money(monthCollected) }}</td></tr></tfoot>
            </table>
          </div>
          <p v-if="!monthPayments.length" class="empty">No receipts in this month.</p>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { logoWhatsapp } from 'ionicons/icons';
import { computed, ref } from 'vue';
import { voucherRun } from '@/lib/actions';
import { localDay, money, nice, niceMonth, percent, period } from '@/lib/format';
import { allLedgers, defaulters, familyLedger, livePayments } from '@/lib/logic/fees';
import { queueMessage, templates, waLink } from '@/lib/logic/messages';
import { canWrite, hasRole, IS_DEMO } from '@/lib/session';
import { all, find, groupBy, type Row } from '@/lib/store';
import { attempt, toast } from '@/lib/ui';

const TABS = [{ id: 'collect', label: 'Collect' }, { id: 'due', label: 'Defaulters' }, { id: 'vouchers', label: 'Vouchers' }, { id: 'daybook', label: 'Receipts' }] as const;
const tab = ref<(typeof TABS)[number]['id']>('collect');
const month = ref(period());
const search = ref('');
const busy = ref(false);
const online = computed(() => IS_DEMO || navigator.onLine);
const canRun = computed(() => hasRole('owner', 'accountant') && canWrite() && online.value);

const kids = (familyId: string) => (groupBy('students', 'family_id').get(familyId) ?? []).filter((s) => s.status === 'active').map((s) => s.name).join(', ');
const states = computed(() => [...allLedgers().values()].flatMap((l) => l.invoices));
const monthInvoices = computed(() => states.value.filter((s) => s.invoice.period === month.value).sort((a, b) => String(a.invoice.number).localeCompare(String(b.invoice.number))));
const monthInvoiced = computed(() => monthInvoices.value.reduce((s, v) => s + v.total, 0));
const monthPayments = computed(() => livePayments().filter((p) => localDay(p.received_at).startsWith(month.value)).sort((a, b) => String(b.received_at).localeCompare(String(a.received_at))));
const monthCollected = computed(() => monthPayments.value.reduce((s, p) => s + p.amount, 0));
const owing = computed(() => defaulters());
const outstanding = computed(() => owing.value.reduce((s, d) => s + d.ledger.balance, 0));
const overdue = computed(() => owing.value.reduce((s, d) => s + d.ledger.overdue, 0));
const methods = computed(() => {
  const m = new Map<string, number>();
  for (const p of monthPayments.value) m.set(p.method, (m.get(p.method) ?? 0) + p.amount);
  return [...m.entries()].map(([method, amount]) => ({ method, amount })).sort((a, b) => b.amount - a.amount);
});
const found = computed(() => {
  const q = search.value.trim().toLowerCase();
  return all('families').filter((f) => [f.primary_guardian_name, f.code, f.primary_phone, kids(f.id)].some((v) => String(v ?? '').toLowerCase().includes(q))).slice(0, 12);
});

async function run() {
  busy.value = true;
  await attempt(async () => {
    const r = await voucherRun(month.value);
    if (r.created) toast(`${r.created} vouchers created, ${money(r.total)} in total.`);
    else if (r.skipped) toast(`All families already have a voucher for ${niceMonth(month.value)}.`, 'info');
    else toast('No vouchers created. Admit students and set the monthly fee for each class in Settings, Fee setup.', 'info');
  });
  busy.value = false;
}
function remind(family: Row) { queueMessage(family.id, 'late_fee', templates.lateFee(family)); }
function remindAll() {
  let n = 0;
  for (const d of owing.value) if (queueMessage(d.family.id, 'late_fee', templates.lateFee(d.family))) n++;
  toast(`${n} fee reminders queued.`);
}
</script>

<style scoped>
.month { width: 170px; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.results { margin: 10px -16px -16px; border-top: 1px solid var(--ilm-line); }
.meth { margin-bottom: 10px; font-size: 0.9rem; text-transform: capitalize; }
.meth .bar { margin-top: 4px; }
.pad { padding: 14px 16px 6px; }
</style>
