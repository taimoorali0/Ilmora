<template>
  <ion-page>
    <PageHeader title="Payslip" :back="mine ? '/me' : '/payroll'" />
    <ion-content>
      <div class="wrap" v-if="slip && staff && allowed">
        <div class="row no-print" style="justify-content:center;margin-bottom:14px"><button class="btn" @click="printPage"><ion-icon :icon="printOutline" /> Print</button></div>
        <div class="sheet">
          <Letterhead />
          <h3 class="doc">Payslip · {{ niceMonth(run?.period ?? '') }}</h3>
          <div class="two">
            <dl class="kv"><dt>Name</dt><dd><b>{{ staff.name }}</b></dd><dt>Designation</dt><dd>{{ staff.designation }}</dd><dt>Employee no</dt><dd>{{ staff.employee_no }}</dd></dl>
            <dl class="kv"><dt>Present</dt><dd>{{ att.present + att.late }} days ({{ att.late }} late)</dd><dt>Absent / unpaid</dt><dd>{{ att.absent }} / {{ att.leave_unpaid }}</dd><dt>Paid leave</dt><dd>{{ att.leave_paid }}</dd></dl>
          </div>
          <div class="two">
            <table class="tbl">
              <thead><tr><th>Earnings</th><th class="right">Rs</th></tr></thead>
              <tbody><tr v-for="l in lines.filter((x) => x.type === 'earning')" :key="l.name"><td>{{ l.name }}</td><td class="right num">{{ l.amount.toLocaleString() }}</td></tr></tbody>
              <tfoot><tr><td>Gross</td><td class="right num">{{ slip.gross.toLocaleString() }}</td></tr></tfoot>
            </table>
            <table class="tbl">
              <thead><tr><th>Deductions</th><th class="right">Rs</th></tr></thead>
              <tbody>
                <tr v-for="l in lines.filter((x) => x.type === 'deduction')" :key="l.name"><td>{{ l.name }}<br /><span class="muted">{{ l.basis }}</span></td><td class="right num">{{ l.amount.toLocaleString() }}</td></tr>
                <tr v-if="!lines.some((x) => x.type === 'deduction')"><td class="muted">None</td><td></td></tr>
              </tbody>
              <tfoot><tr><td>Total</td><td class="right num">{{ slip.total_deductions.toLocaleString() }}</td></tr></tfoot>
            </table>
          </div>
          <div class="net"><span>Net salary</span><b>{{ money(slip.net) }}</b></div>
          <p class="muted">{{ slip.paid_at ? `Paid on ${nice(slip.paid_at)} (${slip.payment_method ?? 'cash'}).` : run?.status === 'approved' ? 'Approved, not yet paid.' : 'Draft: figures can still change.' }}</p>
          <div class="signs"><span>Accounts</span><span>Employee</span></div>
        </div>
      </div>
      <p v-else class="empty">This payslip is not available.</p>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { printOutline } from 'ionicons/icons';
import { computed } from 'vue';
import { money, nice, niceMonth } from '@/lib/format';
import { emptySummary, parseJson, type AttendanceSummary, type PayLine } from '@/lib/logic/payroll';
import { hasRole, session } from '@/lib/session';
import { find } from '@/lib/store';
import { printPage } from '@/lib/ui';

const props = defineProps<{ id: string }>();
const slip = computed(() => find('payslips', props.id));
const run = computed(() => find('payroll_runs', slip.value?.payroll_run_id));
const staff = computed(() => find('staff', slip.value?.staff_id));
const mine = computed(() => !hasRole('owner', 'principal', 'accountant'));
const allowed = computed(() => !mine.value || slip.value?.staff_id === session.data?.user.staff_id);
const lines = computed(() => parseJson<PayLine[]>(slip.value?.lines, []));
const att = computed(() => parseJson<AttendanceSummary>(slip.value?.attendance_summary, emptySummary()));
</script>

<style scoped>
.two { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px 24px; margin-bottom: 16px; align-items: start; }
.net { display: flex; justify-content: space-between; align-items: center; background: #eef7f6; border-radius: 12px; padding: 14px 18px; margin-bottom: 10px; }
.net b { font-size: 1.5rem; color: #071a3a; }
</style>
