<template>
  <ion-page>
    <PageHeader title="Payroll" />
    <ion-content>
      <div class="wrap">
        <div class="row spread" style="margin-bottom:12px">
          <label class="field" style="width:170px"><span>Month</span><input v-model="month" type="month" :max="period()" /></label>
          <div class="row">
            <span v-if="run" class="pill" :class="run.status === 'paid' ? 'ok' : run.status === 'approved' ? 'info' : 'warn'">{{ STATUS[run.status] ?? run.status }}</span>
            <button v-if="canDraft && (!run || run.status === 'draft')" class="btn" :disabled="busy" @click="draft">{{ run ? 'Recalculate' : 'Calculate payroll' }}</button>
            <button v-if="canApprove && run?.status === 'draft'" class="btn primary" :disabled="busy" @click="approve">Approve payroll</button>
            <button v-if="canDraft && run?.status === 'approved'" class="btn primary" :disabled="busy" @click="pay">Mark salaries as paid</button>
          </div>
        </div>
        <p v-if="!online" class="notice warn">Payroll is calculated on the server from synchronized, locked attendance. Connect to the internet to calculate or approve.</p>

        <div class="stats" v-if="run">
          <div class="stat"><b>{{ money(totals.gross) }}</b><span>Gross salaries</span></div>
          <div class="stat"><b>{{ money(totals.deductions) }}</b><span>Deductions</span></div>
          <div class="stat"><b>{{ money(totals.net) }}</b><span>Net payable</span><small>{{ slips.length }} payslips</small></div>
        </div>
        <div v-if="blocked.length" class="notice warn"><b>Not included:</b> {{ blocked.join('; ') }}</div>

        <div class="card flush">
          <div class="tbl-wrap" v-if="slips.length">
            <table class="tbl">
              <thead><tr><th>Staff</th><th class="center">Present</th><th class="center">Absent</th><th class="center">Late</th><th class="right">Gross</th><th class="right">Deductions</th><th class="right">Net</th><th></th></tr></thead>
              <tbody>
                <tr v-for="p in slips" :key="p.id" class="click" @click="$router.push(`/payslip/${p.id}`)">
                  <td><b>{{ staffName(p.staff_id) }}</b><br /><span class="muted">{{ find('staff', p.staff_id)?.designation }}</span></td>
                  <td class="center num">{{ p.att.present + p.att.late }}</td><td class="center num">{{ p.att.absent }}</td><td class="center num">{{ p.att.late }}</td>
                  <td class="right num">{{ money(p.gross) }}</td><td class="right num">{{ money(p.total_deductions) }}</td><td class="right num"><b>{{ money(p.net) }}</b></td>
                  <td><span v-if="p.paid_at" class="pill ok">Paid</span></td>
                </tr>
              </tbody>
              <tfoot><tr><td colspan="4">Total</td><td class="right num">{{ money(totals.gross) }}</td><td class="right num">{{ money(totals.deductions) }}</td><td class="right num">{{ money(totals.net) }}</td><td></td></tr></tfoot>
            </table>
          </div>
          <p v-else class="empty">No payroll for {{ niceMonth(month) }} yet. Calculate it from staff attendance.</p>
        </div>
        <p class="muted">Deductions: absent and unpaid leave days, half days, and one day for every 3 lates, on a working-day basis, plus loan installments. Approval locks the month's attendance.</p>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { payrollApprove, payrollDraft, payrollPay } from '@/lib/actions';
import { addMonths, money, niceMonth, period } from '@/lib/format';
import { emptySummary, parseJson, type AttendanceSummary } from '@/lib/logic/payroll';
import { staffName } from '@/lib/school';
import { canWrite, hasRole, IS_DEMO } from '@/lib/session';
import { all, find, mix, where } from '@/lib/store';
import { ask, attempt, toast } from '@/lib/ui';

const STATUS: Record<string, string> = { draft: 'Draft', approved: 'Approved', paid: 'Paid' };
const month = ref(addMonths(period(), all('payroll_runs').some((r) => r.period === period()) ? 0 : -1));
const busy = ref(false);
const blocked = ref<string[]>([]);
const online = computed(() => IS_DEMO || navigator.onLine);
const canDraft = computed(() => hasRole('owner', 'accountant') && canWrite() && online.value);
const canApprove = computed(() => hasRole('owner', 'principal') && canWrite() && online.value);
const run = computed(() => all('payroll_runs').find((r) => r.period === month.value));
const slips = computed(() => (run.value ? where('payslips', { payroll_run_id: run.value.id }) : [])
  .map((p) => mix(p, { att: parseJson<AttendanceSummary>(p.attendance_summary, emptySummary()) }))
  .sort((a, b) => staffName(a.staff_id).localeCompare(staffName(b.staff_id))));
const totals = computed(() => ({ gross: slips.value.reduce((s, p) => s + p.gross, 0), deductions: slips.value.reduce((s, p) => s + p.total_deductions, 0), net: slips.value.reduce((s, p) => s + p.net, 0) }));

async function act(fn: () => Promise<void>) { busy.value = true; await attempt(fn); busy.value = false; }
const draft = () => act(async () => {
  const r = await payrollDraft(month.value);
  blocked.value = r.blocked;
  toast(`${r.payslips} payslips calculated, net ${money(r.net)}.`);
});
const approve = () => act(async () => {
  if (!(await ask('Approve payroll?', `Attendance for ${niceMonth(month.value)} is locked and payslips become final.`, 'Approve'))) return;
  await payrollApprove(run.value!.id);
  toast('Payroll approved.');
});
const pay = () => act(async () => { await payrollPay(run.value!.id); toast('Salaries marked as paid.'); });
</script>
