<template>
  <ion-page>
    <PageHeader title="Expenses" />
    <ion-content>
      <div class="wrap">
        <div class="stats">
          <div class="stat"><b>{{ money(total) }}</b><span>Spent in {{ niceMonth(month) }}</span><small>{{ rows.length }} entries</small></div>
          <div class="stat"><b>{{ money(collected) }}</b><span>Fees collected</span></div>
          <div class="stat"><b>{{ money(collected - total) }}</b><span>Collection minus expenses</span><small>before salaries</small></div>
        </div>
        <div class="row spread" style="margin-bottom:12px">
          <label class="field" style="width:170px"><input v-model="month" type="month" aria-label="Month" /></label>
          <button v-if="canEdit" class="btn primary" @click="open()"><ion-icon :icon="add" /> Add expense</button>
        </div>
        <div class="cols">
          <div class="card flush">
            <h3>Entries</h3>
            <div class="tbl-wrap">
              <table class="tbl">
                <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Paid to</th><th class="right">Amount</th></tr></thead>
                <tbody>
                  <tr v-for="e in rows" :key="e.id" :class="{ click: canEdit }" @click="canEdit && open(e)">
                    <td class="nowrap">{{ nice(e.spent_on) }}</td><td>{{ e.category }}</td><td>{{ e.description }}</td><td>{{ e.paid_to || '–' }}</td><td class="right num">{{ money(e.amount) }}</td>
                  </tr>
                </tbody>
                <tfoot v-if="rows.length"><tr><td colspan="4">Total</td><td class="right num">{{ money(total) }}</td></tr></tfoot>
              </table>
            </div>
            <p v-if="!rows.length" class="empty">No expenses in this month.</p>
          </div>
          <div class="card">
            <h3>By category</h3>
            <div v-for="c in cats" :key="c.name" style="margin-bottom:10px;font-size:.9rem">
              <div class="row spread"><span>{{ c.name }}</span><b class="num">{{ money(c.amount) }}</b></div>
              <div class="bar" style="margin-top:4px"><i :style="{ width: percent(c.amount, total) + '%' }"></i></div>
            </div>
            <p v-if="!cats.length" class="muted">Nothing to show.</p>
          </div>
        </div>
      </div>
      <AppModal :open="modal" :title="form.id ? 'Edit expense' : 'Add expense'" @close="modal = false">
        <form class="form" @submit.prevent="save">
          <label class="field"><span>Date *</span><input v-model="form.spent_on" type="date" required /></label>
          <label class="field"><span>Category *</span><input v-model="form.category" list="cats" maxlength="40" required /><datalist id="cats"><option v-for="c in CATEGORIES" :key="c" :value="c" /></datalist></label>
          <label class="field wide"><span>Description *</span><input v-model="form.description" maxlength="200" required /></label>
          <label class="field"><span>Amount (Rs) *</span><input v-model.number="form.amount" type="number" min="1" step="1" required /></label>
          <label class="field"><span>Paid to</span><input v-model="form.paid_to" maxlength="100" /></label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = false">Cancel</button><button class="btn primary" type="submit">Save</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { add } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { localDay, money, nice, niceMonth, percent, period, today } from '@/lib/format';
import { livePayments } from '@/lib/logic/fees';
import { canWrite, hasRole } from '@/lib/session';
import { all, insert, update, type Row } from '@/lib/store';
import { toast } from '@/lib/ui';

const CATEGORIES = ['Utilities', 'Stationery', 'Maintenance', 'Events', 'Printing', 'Transport', 'Cleaning', 'Rent', 'Other'];
const month = ref(period());
const canEdit = computed(() => hasRole('owner', 'accountant') && canWrite());
const rows = computed(() => all('expenses').filter((e) => String(e.spent_on).startsWith(month.value)).sort((a, b) => String(b.spent_on).localeCompare(String(a.spent_on))));
const total = computed(() => rows.value.reduce((s, e) => s + e.amount, 0));
const collected = computed(() => livePayments().filter((p) => localDay(p.received_at).startsWith(month.value)).reduce((s, p) => s + p.amount, 0));
const cats = computed(() => {
  const m = new Map<string, number>();
  for (const e of rows.value) m.set(e.category, (m.get(e.category) ?? 0) + e.amount);
  return [...m.entries()].map(([name, amount]) => ({ name, amount })).sort((a, b) => b.amount - a.amount);
});
const modal = ref(false);
const form = reactive({ id: '', spent_on: today(), category: '', description: '', amount: 0, paid_to: '' });
function open(e?: Row) {
  Object.assign(form, e ? { id: e.id, spent_on: e.spent_on, category: e.category, description: e.description, amount: e.amount, paid_to: e.paid_to ?? '' }
    : { id: '', spent_on: today(), category: '', description: '', amount: 0, paid_to: '' });
  modal.value = true;
}
function save() {
  const values = { spent_on: form.spent_on, category: form.category.trim(), description: form.description.trim(), amount: Math.round(Number(form.amount)), paid_to: form.paid_to.trim() || null };
  if (values.amount <= 0) { toast('Enter the amount in whole rupees.', 'bad'); return; }
  if (form.id) update('expenses', form.id, values); else insert('expenses', values);
  modal.value = false; toast('Expense saved.');
}
</script>
