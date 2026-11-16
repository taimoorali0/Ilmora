<template>
  <ion-page>
    <PageHeader title="Staff" />
    <ion-content>
      <div class="wrap">
        <div class="stats">
          <div class="stat"><b>{{ active.length }}</b><span>Staff members</span></div>
          <div class="stat"><b>{{ todayCount.in }}</b><span>Present today</span><small>{{ todayCount.late }} late</small></div>
          <div class="stat"><b>{{ todayCount.out }}</b><span>Absent or on leave</span></div>
          <div class="stat"><b>{{ requests.length }}</b><span>Leave requests waiting</span></div>
        </div>
        <div class="row spread" style="margin-bottom:12px">
          <div class="seg">
            <button :class="{ on: tab === 'list' }" @click="tab = 'list'">Staff</button>
            <button :class="{ on: tab === 'att' }" @click="tab = 'att'">Attendance</button>
            <button :class="{ on: tab === 'leave' }" @click="tab = 'leave'">Leave ({{ requests.length }})</button>
          </div>
          <button v-if="canEdit && tab === 'list'" class="btn primary" @click="open()"><ion-icon :icon="add" /> Add staff</button>
          <label v-if="tab === 'att'" class="field" style="width:170px"><input v-model="date" type="date" :max="today()" aria-label="Date" /></label>
        </div>

        <div v-if="tab === 'list'" class="card flush">
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>Name</th><th>Designation</th><th>Phone</th><th>Joined</th><th class="right">Salary</th><th>This month</th><th></th></tr></thead>
              <tbody>
                <tr v-for="s in staff" :key="s.id">
                  <td><b>{{ s.name }}</b><br /><span class="muted">{{ s.employee_no }}</span></td><td>{{ s.designation }}</td><td class="nowrap">{{ s.phone }}</td><td class="nowrap">{{ nice(s.joining_date) }}</td>
                  <td class="right num">{{ money((s.basic_salary ?? 0) + (s.allowances ?? 0)) }}</td>
                  <td class="nowrap"><span class="muted">{{ monthLine(s.id) }}</span></td>
                  <td class="right"><button v-if="canEdit" class="btn sm" @click="open(s)">Edit</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="tab === 'att'" class="card flush">
          <p class="muted pad">With a biometric device, punches fill this sheet on the server. Without one, mark it here. Days locked by an approved payroll cannot be changed.</p>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>Name</th><th>In</th><th>Out</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="r in dayRows" :key="r.staff.id">
                  <td><b>{{ r.staff.name }}</b><br /><span class="muted">{{ r.staff.designation }}</span></td>
                  <td class="nowrap">{{ time(r.day?.check_in) || '–' }}</td><td class="nowrap">{{ time(r.day?.check_out) || '–' }}</td>
                  <td>
                    <select class="input st" :value="r.day?.status ?? ''" :disabled="!canEdit || r.day?.locked" :aria-label="`Status of ${r.staff.name}`" @change="setDay(r.staff.id, ($event.target as HTMLSelectElement).value)">
                      <option value="" disabled>Not marked</option>
                      <option v-for="(label, key) in DAY" :key="key" :value="key">{{ label }}</option>
                    </select>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="tab === 'leave'" class="card flush">
          <div class="list">
            <div v-for="l in leaves" :key="l.id" class="item">
              <div class="grow">
                <h4>{{ staffName(l.staff_id) }} <span class="muted">· {{ find('leave_types', l.leave_type_id)?.name }} leave</span></h4>
                <p>{{ nice(l.from_date) }}{{ l.to_date !== l.from_date ? ' to ' + nice(l.to_date) : '' }} · {{ l.reason }}</p>
              </div>
              <template v-if="l.status === 'requested' && canDecide">
                <button class="btn sm danger" :disabled="busy" @click="decide(l.id, 'rejected')">Reject</button>
                <button class="btn sm primary" :disabled="busy" @click="decide(l.id, 'approved')">Approve</button>
              </template>
              <span v-else class="pill" :class="l.status === 'approved' ? 'ok' : l.status === 'rejected' ? 'bad' : 'warn'">{{ l.status }}</span>
            </div>
            <p v-if="!leaves.length" class="empty">No leave requests.</p>
          </div>
        </div>
      </div>

      <AppModal :open="modal" :title="form.id ? 'Edit staff member' : 'Add staff member'" @close="modal = false">
        <form class="form" @submit.prevent="save">
          <label class="field"><span>Name *</span><input v-model="form.name" maxlength="100" required /></label>
          <label class="field"><span>Designation *</span><input v-model="form.designation" maxlength="60" list="desig" required /><datalist id="desig"><option v-for="d in ['Teacher', 'Principal', 'Accountant', 'Admin officer', 'Guard', 'Peon', 'Driver']" :key="d" :value="d" /></datalist></label>
          <label class="field"><span>Phone</span><input v-model="form.phone" inputmode="tel" /></label>
          <label class="field"><span>CNIC</span><input :value="form.cnic" @input="form.cnic = formatCnic(($event.target as HTMLInputElement).value)" placeholder="12345-1234567-1" /></label>
          <label class="field"><span>Joining date</span><input v-model="form.joining_date" type="date" /></label>
          <label class="field"><span>Biometric device ID</span><input v-model="form.device_user_id" maxlength="20" /></label>
          <label class="field"><span>Basic salary (Rs)</span><input v-model.number="form.basic_salary" type="number" min="0" step="1" /></label>
          <label class="field"><span>Allowances (Rs)</span><input v-model.number="form.allowances" type="number" min="0" step="1" /></label>
          <label class="field"><span>Status</span><select v-model="form.status"><option value="active">Active</option><option value="left">Left</option></select></label>
          <div class="actions wide"><button type="button" class="btn" @click="modal = false">Cancel</button><button class="btn primary" type="submit">Save</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { add } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { decideLeave } from '@/lib/actions';
import { formatCnic, money, nice, period, time, today } from '@/lib/format';
import { attendanceSummary } from '@/lib/logic/payroll';
import { isManager, staffName } from '@/lib/school';
import { canWrite, hasRole } from '@/lib/session';
import { all, find, insert, update, upsert, where, type Row } from '@/lib/store';
import { attempt, toast } from '@/lib/ui';

const DAY: Record<string, string> = { present: 'Present', late: 'Late', half_day: 'Half day', absent: 'Absent', leave_paid: 'Paid leave', leave_unpaid: 'Unpaid leave', holiday: 'Holiday', weekly_off: 'Weekly off' };
const tab = ref<'list' | 'att' | 'leave'>('list');
const date = ref(today());
const busy = ref(false);
const canEdit = computed(() => isManager() && canWrite());
const canDecide = computed(() => hasRole('owner', 'principal') && canWrite());
const staff = computed(() => [...all('staff')].sort((a, b) => String(a.employee_no).localeCompare(String(b.employee_no))));
const active = computed(() => staff.value.filter((s) => s.status !== 'left'));
const dayRows = computed(() => {
  const by = new Map(where('staff_attendance_days', { shift_date: date.value }).map((d) => [d.staff_id, d]));
  return active.value.map((s) => ({ staff: s, day: by.get(s.id) }));
});
const todayCount = computed(() => {
  const days = where('staff_attendance_days', { shift_date: today() });
  return { in: days.filter((d) => ['present', 'late', 'half_day'].includes(d.status)).length, late: days.filter((d) => d.status === 'late').length,
    out: days.filter((d) => ['absent', 'leave_paid', 'leave_unpaid'].includes(d.status)).length };
});
const leaves = computed(() => [...all('staff_leaves')].sort((a, b) => (a.status === 'requested' ? 0 : 1) - (b.status === 'requested' ? 0 : 1) || String(b.from_date).localeCompare(String(a.from_date))));
const requests = computed(() => leaves.value.filter((l) => l.status === 'requested'));
function monthLine(staffId: string) {
  const a = attendanceSummary(staffId, period());
  return `${a.present + a.late + a.half_day} present · ${a.absent} absent · ${a.late} late`;
}
function setDay(staffId: string, status: string) {
  upsert('staff_attendance_days', { staff_id: staffId, shift_date: date.value }, { status, check_in: null, check_out: null, late_minutes: 0, early_leave_minutes: 0, overtime_minutes: 0, punch_count: 0 });
}
async function decide(id: string, status: 'approved' | 'rejected') {
  busy.value = true;
  await attempt(() => decideLeave(id, status), `Leave ${status}.`);
  busy.value = false;
}

const modal = ref(false);
const blank = { id: '', name: '', designation: 'Teacher', phone: '', cnic: '', joining_date: today(), device_user_id: '', basic_salary: 0, allowances: 0, status: 'active' };
const form = reactive({ ...blank });
function open(s?: Row) {
  Object.assign(form, s ? { id: s.id, name: s.name, designation: s.designation, phone: s.phone ?? '', cnic: s.cnic ?? '', joining_date: s.joining_date ?? '', device_user_id: s.device_user_id ?? '',
    basic_salary: s.basic_salary ?? 0, allowances: s.allowances ?? 0, status: s.status ?? 'active' } : { ...blank });
  modal.value = true;
}
function save() {
  const values = { name: form.name.trim(), designation: form.designation.trim(), phone: form.phone.trim() || null, cnic: form.cnic || null, joining_date: form.joining_date || null,
    device_user_id: form.device_user_id.trim() || null, basic_salary: Math.round(Number(form.basic_salary) || 0), allowances: Math.round(Number(form.allowances) || 0), status: form.status };
  if (form.id) update('staff', form.id, values);
  else insert('staff', { ...values, employee_no: `EMP-${String(all('staff').length + 1).padStart(3, '0')}`, shift_id: all('shifts')[0]?.id ?? null, bank_name: null, bank_account: null });
  modal.value = false; toast('Staff member saved.');
}
</script>

<style scoped>
.pad { padding: 12px 16px 0; margin: 0; }
.st { width: 150px; min-height: 36px; padding: 6px 8px; font-size: 0.85rem; }
</style>
