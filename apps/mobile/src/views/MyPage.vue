<template>
  <ion-page>
    <PageHeader title="My attendance & pay" />
    <ion-content>
      <div class="wrap narrow">
        <p v-if="!me" class="empty">Your login is not linked to a staff record. Ask the school office to link it.</p>
        <template v-else>
          <div class="stats">
            <div class="stat"><b>{{ att.present + att.late + att.half_day }}</b><span>Days present, {{ niceMonth(period()) }}</span></div>
            <div class="stat"><b>{{ att.late }}</b><span>Late</span></div>
            <div class="stat"><b>{{ att.absent }}</b><span>Absent</span></div>
            <div class="stat"><b>{{ att.leave_paid + att.leave_unpaid }}</b><span>Leave</span></div>
          </div>
          <div class="card flush">
            <div class="row spread pad"><h3 style="margin:0">My leave</h3><button class="btn primary sm" :disabled="!canWrite()" @click="open">Request leave</button></div>
            <div class="list">
              <div v-for="l in leaves" :key="l.id" class="item">
                <div class="grow"><h4>{{ find('leave_types', l.leave_type_id)?.name }} leave</h4><p>{{ nice(l.from_date) }}{{ l.to_date !== l.from_date ? ' to ' + nice(l.to_date) : '' }} · {{ l.reason }}</p></div>
                <span class="pill" :class="l.status === 'approved' ? 'ok' : l.status === 'rejected' ? 'bad' : 'warn'">{{ l.status }}</span>
              </div>
              <p v-if="!leaves.length" class="empty">No leave requests yet.</p>
            </div>
          </div>
          <div class="card flush">
            <h3>My payslips</h3>
            <div class="list">
              <div v-for="p in slips" :key="p.id" class="item click" @click="$router.push(`/payslip/${p.id}`)">
                <div class="grow"><h4>{{ niceMonth(p.period) }}</h4><p>{{ p.paid_at ? 'Paid ' + nice(p.paid_at) : 'Not paid yet' }}</p></div>
                <b class="num">{{ money(p.net) }}</b>
              </div>
              <p v-if="!slips.length" class="empty">No payslips yet.</p>
            </div>
          </div>
        </template>
      </div>
      <AppModal :open="modal" title="Request leave" @close="modal = false">
        <form class="form" @submit.prevent="save">
          <label class="field wide"><span>Leave type</span><select v-model="form.leave_type_id" required><option v-for="t in all('leave_types')" :key="t.id" :value="t.id">{{ t.name }}{{ t.paid ? '' : ' (unpaid)' }}</option></select></label>
          <label class="field"><span>From *</span><input v-model="form.from_date" type="date" required /></label>
          <label class="field"><span>To *</span><input v-model="form.to_date" type="date" required /></label>
          <label class="field wide"><span>Reason *</span><input v-model="form.reason" maxlength="200" required /></label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = false">Cancel</button><button class="btn primary" type="submit">Send request</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { addDays, money, nice, niceMonth, period, today } from '@/lib/format';
import { attendanceSummary } from '@/lib/logic/payroll';
import { canWrite, session } from '@/lib/session';
import { all, find, insert, mix, where } from '@/lib/store';
import { toast } from '@/lib/ui';

const me = computed(() => find('staff', session.data?.user.staff_id));
const att = computed(() => attendanceSummary(me.value?.id ?? '', period()));
const leaves = computed(() => where('staff_leaves', { staff_id: me.value?.id }).sort((a, b) => String(b.from_date).localeCompare(String(a.from_date))));
const slips = computed(() => where('payslips', { staff_id: me.value?.id }).map((p) => mix(p, { period: String(find('payroll_runs', p.payroll_run_id)?.period ?? ''), run: find('payroll_runs', p.payroll_run_id) }))
  .filter((p) => p.run && p.run.status !== 'draft').sort((a, b) => b.period.localeCompare(a.period)));
const modal = ref(false);
const form = reactive({ leave_type_id: '', from_date: '', to_date: '', reason: '' });
function open() { Object.assign(form, { leave_type_id: all('leave_types')[0]?.id ?? '', from_date: addDays(today(), 1), to_date: addDays(today(), 1), reason: '' }); modal.value = true; }
function save() {
  if (form.to_date < form.from_date) { toast('The end date is before the start date.', 'bad'); return; }
  insert('staff_leaves', { staff_id: me.value!.id, ...form, reason: form.reason.trim(), half_day: false, status: 'requested', decided_by: null, decided_at: null });
  modal.value = false; toast('Leave request sent to the principal.');
}
</script>

<style scoped>.pad { padding: 14px 16px 6px; }</style>
