<template>
  <ion-page>
    <PageHeader title="Settings" />
    <ion-content>
      <div class="wrap">
        <div class="seg" style="margin-bottom:14px">
          <button v-for="t in TABS" :key="t.id" :class="{ on: tab === t.id }" @click="tab = t.id">{{ t.label }}</button>
        </div>

        <template v-if="tab === 'school'">
          <div class="cols">
            <div class="card">
              <h3>School</h3>
              <dl class="kv">
                <dt>Name</dt><dd>{{ school?.name }}</dd><dt>School code</dt><dd>{{ school?.slug }}</dd><dt>Phone</dt><dd>{{ school?.phone || '–' }}</dd><dt>Address</dt><dd>{{ school?.address || '–' }}</dd>
                <dt>Campuses</dt><dd>{{ branches }}</dd><dt>Session</dt><dd>{{ currentSession()?.name }}</dd><dt>Plan</dt><dd>{{ plan?.name ?? '–' }}{{ sub ? ' · valid to ' + nice(sub.ends_on) : '' }}</dd>
              </dl>
              <p class="muted" style="margin:12px 0 0">The school name, code and plan are managed by Ilmora support.</p>
            </div>
            <div class="card">
              <h3>This device</h3>
              <dl class="kv">
                <dt>Device code</dt><dd>{{ session.data?.device.code }}</dd><dt>Mode</dt><dd>{{ IS_DEMO ? 'Demo: data stays in this browser' : 'Connected to the school server' }}</dd>
                <dt v-if="!IS_DEMO">Last sync</dt><dd v-if="!IS_DEMO">{{ meta.lastSync ? nice(meta.lastSync) + ' ' + time(meta.lastSync) : 'Not yet' }}</dd>
                <dt v-if="!IS_DEMO">Waiting to upload</dt><dd v-if="!IS_DEMO">{{ meta.pending }} changes</dd>
                <dt v-if="!IS_DEMO">Offline access until</dt><dd v-if="!IS_DEMO">{{ nice(session.data?.device.offline_valid_until) }}</dd>
              </dl>
              <div class="row" style="margin-top:12px">
                <button v-if="!IS_DEMO" class="btn" :disabled="meta.syncing" @click="syncNow">{{ meta.syncing ? 'Syncing…' : 'Sync now' }}</button>
                <button v-if="IS_DEMO" class="btn danger" @click="resetDemo">Reset demo data</button>
              </div>
            </div>
          </div>
          <div class="card flush" v-if="meta.issues.length">
            <h3>Changes the server did not accept</h3>
            <div class="list"><div v-for="(i, n) in meta.issues" :key="n" class="item"><span class="pill bad">{{ i.result }}</span><div class="grow"><h4>{{ i.table }}</h4><p>{{ i.message }} · {{ nice(i.at) }}</p></div></div></div>
          </div>
        </template>

        <template v-if="tab === 'fees'">
          <div class="card flush">
            <h3>Monthly fee by class</h3>
            <div class="tbl-wrap">
              <table class="tbl">
                <thead><tr><th>Class</th><th v-for="h in heads" :key="h.id" class="right">{{ h.name }}</th></tr></thead>
                <tbody>
                  <tr v-for="c in sortedClasses()" :key="c.id">
                    <td><b>{{ className(c.id) }}</b></td>
                    <td v-for="h in heads" :key="h.id" class="right"><input class="input amt" type="number" min="0" step="50" :value="structure(c.id, h.id)?.amount ?? ''" :disabled="!canFees" :aria-label="`${h.name} for ${className(c.id)}`" @change="setFee(c.id, h, ($event.target as HTMLInputElement).value)" /></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="muted pad">Tuition is charged every month. Annual charges are added in April; exam fee in September and March. Changes apply to the next voucher run.</p>
          </div>
          <div class="cols">
            <div class="card">
              <h3>Sibling discount on tuition</h3>
              <div class="form">
                <label class="field" v-for="k in ['2', '3', '4+']" :key="k"><span>{{ k === '4+' ? '4th child onwards' : k === '2' ? '2nd child' : '3rd child' }} (%)</span><input type="number" min="0" max="100" :value="siblingMap[k] ?? 0" :disabled="!canFees" @change="setSibling(k, ($event.target as HTMLInputElement).value)" /></label>
              </div>
            </div>
            <div class="card">
              <h3>Late fee</h3>
              <div class="form">
                <label class="field"><span>Amount added to the next voucher (Rs)</span><input type="number" min="0" step="50" :value="fine?.amount ?? 0" :disabled="!canFees" @change="setFine(($event.target as HTMLInputElement).value)" /></label>
              </div>
            </div>
          </div>
        </template>

        <template v-if="tab === 'users'">
          <div class="row spread" style="margin-bottom:12px"><p class="muted" style="margin:0">Each person signs in with the school code, their phone number and a password.</p><button v-if="canUsers" class="btn primary" @click="openUser()"><ion-icon :icon="add" /> Add login</button></div>
          <div class="card flush">
            <div class="tbl-wrap">
              <table class="tbl">
                <thead><tr><th>Name</th><th>Phone</th><th>Role</th><th>Linked to</th><th>Status</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="u in users" :key="u.id">
                    <td><b>{{ u.name }}</b></td><td class="nowrap">{{ u.phone }}</td><td>{{ roleLabel(u.role) }}</td><td>{{ u.linked }}</td>
                    <td><span class="pill" :class="u.status === 'active' ? 'ok' : 'bad'">{{ u.status }}</span></td>
                    <td class="right"><button v-if="canUsers && u.role !== 'owner'" class="btn sm" @click="openUser(u)">Edit</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>

        <template v-if="tab === 'calendar'">
          <div class="card">
            <h3>Add a holiday</h3>
            <form class="form" @submit.prevent="addHoliday">
              <label class="field"><span>Date *</span><input v-model="holiday.date" type="date" required /></label>
              <label class="field"><span>Name *</span><input v-model="holiday.name" maxlength="80" required /></label>
              <div class="actions wide"><button class="btn primary" type="submit" :disabled="!canWrite()">Add holiday</button></div>
            </form>
          </div>
          <div class="card flush">
            <h3>Holidays</h3>
            <div class="list"><div v-for="h in holidays" :key="h.id" class="item"><div class="grow"><h4>{{ h.name }}</h4><p>{{ nice(h.date) }}</p></div></div></div>
          </div>
        </template>
      </div>

      <AppModal :open="modal" :title="userForm.id ? 'Edit login' : 'Add login'" @close="modal = false">
        <form class="form" @submit.prevent="saveUserForm">
          <label class="field"><span>Role *</span><select v-model="userForm.role" :disabled="!!userForm.id"><option v-for="r in ROLES" :key="r" :value="r">{{ roleLabel(r) }}</option></select></label>
          <label v-if="userForm.role === 'parent'" class="field"><span>Family *</span><select v-model="userForm.family_id" required @change="fillFromFamily"><option v-for="f in families" :key="f.id" :value="f.id">{{ f.primary_guardian_name }} · {{ f.code }}</option></select></label>
          <label v-else class="field"><span>Staff record</span><select v-model="userForm.staff_id" @change="fillFromStaff"><option value="">Not linked</option><option v-for="s in all('staff')" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
          <label class="field"><span>Name *</span><input v-model="userForm.name" maxlength="100" required /></label>
          <label class="field"><span>Phone (login) *</span><input v-model="userForm.phone" inputmode="tel" required /></label>
          <label class="field"><span>{{ userForm.id ? 'New password (leave empty to keep)' : 'Password *' }}</span><input v-model="userForm.password" type="text" minlength="6" :required="!userForm.id" autocomplete="off" /></label>
          <label class="field"><span>Status</span><select v-model="userForm.status"><option value="active">Active</option><option value="disabled">Disabled</option></select></label>
          <p v-if="error" class="error wide" role="alert">{{ error }}</p>
          <div class="actions wide"><button type="button" class="btn" @click="modal = false">Cancel</button><button class="btn primary" type="submit" :disabled="busy">Save</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { add } from 'ionicons/icons';
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { saveUser } from '@/lib/actions';
import { seedDemo } from '@/lib/demo';
import { nice, normalizePhone, PHONE_RE, time } from '@/lib/format';
import { parseJson } from '@/lib/logic/payroll';
import { className, currentSession, roleLabel, sortedClasses } from '@/lib/school';
import { canWrite, clearSession, hasRole, IS_DEMO, schoolId, session } from '@/lib/session';
import { all, find, insert, meta, mix, raw, update, type Row } from '@/lib/store';
import { syncNow } from '@/lib/sync';
import { ask, toast } from '@/lib/ui';

const TABS = [{ id: 'school', label: 'School & device' }, { id: 'fees', label: 'Fee setup' }, { id: 'users', label: 'Logins' }, { id: 'calendar', label: 'Holidays' }] as const;
const ROLES = ['principal', 'admin', 'accountant', 'examiner', 'teacher', 'parent'];
const router = useRouter();
const tab = ref<(typeof TABS)[number]['id']>('school');
const school = computed(() => raw('schools').find((s) => s.id === schoolId()) ?? session.data?.user.school);
const branches = computed(() => session.data?.user.branches.map((b) => b.name).join(', '));
const sub = computed(() => all('subscriptions')[0]);
const plan = computed(() => raw('plans').find((p) => p.id === sub.value?.plan_id));

// ----- fees -----
const canFees = computed(() => hasRole('owner') && canWrite());
const heads = computed(() => all('fee_heads').filter((h) => h.active !== false && h.frequency !== 'one_time'));
const structure = (classId: string, headId: string) => all('fee_structures').find((f) => f.class_id === classId && f.fee_head_id === headId && f.academic_session_id === currentSession()?.id);
function setFee(classId: string, head: Row, value: string) {
  const amount = Math.round(Number(value));
  if (!Number.isFinite(amount) || amount < 0) return;
  const row = structure(classId, head.id);
  if (row) update('fee_structures', row.id, { amount });
  else insert('fee_structures', { academic_session_id: currentSession()?.id, class_id: classId, fee_head_id: head.id, amount, due_day: 10,
    months: head.frequency === 'yearly' ? [4] : head.frequency === 'per_term' ? [9, 3] : null });
  toast('Fee saved.');
}
const sibling = computed(() => all('discount_rules').find((r) => r.kind === 'sibling'));
const siblingMap = computed(() => parseJson<Record<string, number>>(sibling.value?.config, {}));
function setSibling(key: string, value: string) {
  if (!sibling.value) return;
  update('discount_rules', sibling.value.id, { config: { ...siblingMap.value, [key]: Math.max(0, Math.min(100, Math.round(Number(value) || 0))) } });
  toast('Discount saved.');
}
const fine = computed(() => all('fine_rules').find((r) => r.active !== false));
function setFine(value: string) { if (fine.value) { update('fine_rules', fine.value.id, { amount: Math.max(0, Math.round(Number(value) || 0)) }); toast('Late fee saved.'); } }

// ----- users -----
const canUsers = computed(() => hasRole('owner', 'principal') && canWrite());
const families = computed(() => [...all('families')].sort((a, b) => a.primary_guardian_name.localeCompare(b.primary_guardian_name)));
const users = computed(() => all('users').map((u) => mix(u, { role: String(all('user_roles').find((r) => r.user_id === u.id)?.role ?? ''),
  linked: String(u.staff_id ? find('staff', u.staff_id)?.designation ?? 'Staff' : u.family_id ? `Family ${find('families', u.family_id)?.code ?? ''}` : '–') }))
  .sort((a, b) => ROLES.indexOf(a.role) - ROLES.indexOf(b.role) || a.name.localeCompare(b.name)));
const modal = ref(false);
const busy = ref(false);
const error = ref('');
const userForm = reactive({ id: '', name: '', phone: '', password: '', role: 'teacher', status: 'active', staff_id: '', family_id: '' });
function openUser(u?: Row) {
  Object.assign(userForm, u ? { id: u.id, name: u.name, phone: u.phone ?? '', password: '', role: u.role, status: u.status, staff_id: u.staff_id ?? '', family_id: u.family_id ?? '' }
    : { id: '', name: '', phone: '', password: '', role: 'teacher', status: 'active', staff_id: '', family_id: '' });
  error.value = ''; modal.value = true;
}
function fillFromStaff() { const s = find('staff', userForm.staff_id); if (s && !userForm.id) { userForm.name = s.name; userForm.phone = s.phone ?? ''; } }
function fillFromFamily() { const f = find('families', userForm.family_id); if (f && !userForm.id) { userForm.name = f.primary_guardian_name; userForm.phone = f.primary_phone ?? ''; } }
async function saveUserForm() {
  const phone = normalizePhone(userForm.phone);
  if (!PHONE_RE.test(phone)) { error.value = 'Phone must be a mobile number like 03001234567.'; return; }
  if (userForm.role === 'parent' && !userForm.family_id) { error.value = 'Choose the family for this parent login.'; return; }
  busy.value = true; error.value = '';
  try {
    await saveUser({ id: userForm.id || undefined, name: userForm.name.trim(), phone, password: userForm.password || undefined, role: userForm.role, status: userForm.status,
      staff_id: userForm.role === 'parent' ? null : userForm.staff_id || null, family_id: userForm.role === 'parent' ? userForm.family_id : null });
    modal.value = false; toast('Login saved.');
  } catch (e) { error.value = (e as Error).message; } finally { busy.value = false; }
}

// ----- calendar -----
const holidays = computed(() => [...all('holidays')].sort((a, b) => String(a.date).localeCompare(String(b.date))));
const holiday = reactive({ date: '', name: '' });
function addHoliday() {
  if (all('holidays').some((h) => h.date === holiday.date)) { toast('This date is already a holiday.', 'bad'); return; }
  insert('holidays', { date: holiday.date, name: holiday.name.trim(), for_staff: true, for_students: true });
  Object.assign(holiday, { date: '', name: '' }); toast('Holiday added.');
}

async function resetDemo() {
  if (!(await ask('Reset demo data?', 'Everything you added is removed and the sample school is restored. You will be signed out.', 'Reset', true))) return;
  seedDemo();
  await clearSession();
  router.replace('/login');
}
</script>

<style scoped>
.amt { width: 110px; text-align: right; display: inline-block; min-height: 36px; padding: 6px 8px; }
.pad { padding: 0 16px 14px; margin: 10px 0 0; }
</style>
