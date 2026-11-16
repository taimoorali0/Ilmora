<template>
  <ion-page>
    <ion-header>
      <ion-toolbar color="dark">
        <ion-title>Ilmora platform</ion-title>
        <ion-buttons slot="end"><button class="out" @click="signOut">Sign out</button></ion-buttons>
      </ion-toolbar>
    </ion-header>
    <ion-content>
      <div class="wrap">
        <div class="row spread" style="margin-bottom:14px">
          <div><h2 style="margin:0;font-size:1.25rem">Schools</h2><p class="muted" style="margin:2px 0 0">Super admin console. Only you can create a school; schools cannot sign up themselves.</p></div>
          <button class="btn primary" @click="open"><ion-icon :icon="add" /> New school</button>
        </div>
        <div class="stats">
          <div class="stat"><b>{{ schools.length }}</b><span>Schools</span><small>{{ schools.filter((s) => s.status === 'active').length }} active</small></div>
          <div class="stat"><b>{{ schools.reduce((t, s) => t + s.students, 0) }}</b><span>Students</span></div>
          <div class="stat"><b>{{ schools.reduce((t, s) => t + s.staff, 0) }}</b><span>Staff</span></div>
          <div class="stat"><b>{{ money(mrr) }}</b><span>Monthly subscriptions</span></div>
        </div>

        <div class="card flush">
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>School</th><th>Code</th><th>Plan</th><th>Owner login</th><th class="right">Students</th><th class="right">Staff</th><th>Valid to</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <tr v-for="s in schools" :key="s.id">
                  <td><b>{{ s.name }}</b><br /><span class="muted">{{ s.address }}</span></td><td>{{ s.slug }}</td><td>{{ planName(s.plan_id) }}</td><td class="nowrap">{{ s.owner_phone ?? '–' }}</td>
                  <td class="right num">{{ s.students }}</td><td class="right num">{{ s.staff }}</td><td class="nowrap">{{ nice(s.ends_on) || '–' }}</td>
                  <td><span class="pill" :class="s.status === 'active' ? 'ok' : s.status === 'read_only' ? 'warn' : 'bad'">{{ STATUS[s.status] ?? s.status }}</span></td>
                  <td class="right nowrap">
                    <button v-if="s.status !== 'active'" class="btn sm" :disabled="busy" @click="setStatus(s, 'active')">Activate</button>
                    <button v-if="s.status === 'active'" class="btn sm" :disabled="busy" @click="setStatus(s, 'read_only')">Read-only</button>
                    <button v-if="s.status !== 'suspended'" class="btn sm danger" :disabled="busy" @click="setStatus(s, 'suspended')">Suspend</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="!schools.length" class="empty">No schools yet. Create the first one.</p>
        </div>

        <div class="card flush">
          <h3>Plans</h3>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>Plan</th><th class="right">Students</th><th class="right">Staff</th><th class="right">Campuses</th><th class="right">Per month</th><th class="right">Per year</th></tr></thead>
              <tbody><tr v-for="p in plans" :key="p.id"><td><b>{{ p.name }}</b></td><td class="right num">{{ p.max_students ?? 'Unlimited' }}</td><td class="right num">{{ p.max_staff ?? 'Unlimited' }}</td><td class="right num">{{ p.max_branches }}</td><td class="right num">{{ money(p.price_monthly) }}</td><td class="right num">{{ money(p.price_yearly) }}</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <AppModal :open="modal" title="New school" wide @close="modal = false">
        <form class="form" @submit.prevent="save">
          <label class="field"><span>School name *</span><input v-model="form.name" maxlength="120" required @input="suggest" /></label>
          <label class="field"><span>School code (used at login) *</span><input v-model="form.slug" maxlength="40" pattern="[a-z0-9\-]+" required autocapitalize="off" /></label>
          <label class="field"><span>Phone</span><input v-model="form.phone" maxlength="30" /></label>
          <label class="field"><span>Plan *</span><select v-model="form.plan_id" required><option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }} · {{ money(p.price_monthly) }}/month</option></select></label>
          <label class="field wide"><span>Address</span><input v-model="form.address" maxlength="255" /></label>
          <label class="field"><span>First campus name</span><input v-model="form.branch_name" maxlength="80" placeholder="Main Campus" /></label>
          <label class="field"><span>Owner name *</span><input v-model="form.owner_name" maxlength="120" required /></label>
          <label class="field"><span>Owner phone (login) *</span><input v-model="form.owner_phone" inputmode="tel" placeholder="03001234567" required /></label>
          <label class="field"><span>Owner password *</span><input v-model="form.owner_password" type="text" minlength="6" required autocomplete="off" /></label>
          <p class="muted wide">The school starts with classes Play Group to 10, fee heads, a sibling discount rule, leave types and a morning shift. The owner signs in and adds the rest.</p>
          <p v-if="error" class="error wide" role="alert">{{ error }}</p>
          <div class="actions wide"><button type="button" class="btn" @click="modal = false">Cancel</button><button class="btn primary" type="submit" :disabled="busy">Create school</button></div>
        </form>
      </AppModal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { add } from 'ionicons/icons';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { createSchool, setSchoolStatus } from '@/lib/actions';
import { logout, refreshPlatform } from '@/lib/auth';
import { money, nice, normalizePhone, PHONE_RE } from '@/lib/format';
import { IS_DEMO } from '@/lib/session';
import { raw, type Row } from '@/lib/store';
import { ask, attempt, toast } from '@/lib/ui';

const STATUS: Record<string, string> = { active: 'Active', read_only: 'Read-only', suspended: 'Suspended', closed: 'Closed' };
const router = useRouter();
const busy = ref(false);
const plans = computed(() => [...raw('plans')].sort((a, b) => a.price_monthly - b.price_monthly));
const planName = (id: string) => plans.value.find((p) => p.id === id)?.name ?? '–';
/** The server sends the counts; the demo works them out from the data on this device. */
const schools = computed(() => [...raw('schools')].sort((a, b) => a.name.localeCompare(b.name)).map((s) => {
  if (!IS_DEMO) return { ...s, students: s.students ?? 0, staff: s.staff ?? 0 } as Row;
  const sub = raw('subscriptions').find((x) => x.school_id === s.id);
  const ownerId = raw('user_roles').find((r) => r.school_id === s.id && r.role === 'owner')?.user_id;
  return { ...s, plan_id: sub?.plan_id, ends_on: sub?.ends_on, owner_phone: raw('users').find((u) => u.id === ownerId)?.phone,
    students: raw('students').filter((x) => x.school_id === s.id && x.status === 'active').length, staff: raw('staff').filter((x) => x.school_id === s.id).length } as Row;
}));
const mrr = computed(() => schools.value.filter((s) => s.status === 'active').reduce((t, s) => t + (plans.value.find((p) => p.id === s.plan_id)?.price_monthly ?? 0), 0));
onMounted(() => { refreshPlatform().catch(() => toast('Could not load schools. Check the connection.', 'bad')); });

const modal = ref(false);
const error = ref('');
const blank = { name: '', slug: '', phone: '', address: '', branch_name: '', owner_name: '', owner_phone: '', owner_password: '', plan_id: '' };
const form = reactive({ ...blank });
let touched = false;
function open() { Object.assign(form, { ...blank, plan_id: plans.value[0]?.id ?? '' }); touched = false; error.value = ''; modal.value = true; }
function suggest() { if (!touched) form.slug = form.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 24); }
async function save() {
  error.value = '';
  const phone = normalizePhone(form.owner_phone);
  if (!PHONE_RE.test(phone)) { error.value = 'Owner phone must be a mobile number like 03001234567.'; return; }
  busy.value = true;
  try {
    await createSchool({ ...form, slug: form.slug.trim().toLowerCase(), owner_phone: phone, phone: form.phone || undefined, address: form.address || undefined, branch_name: form.branch_name || undefined });
    modal.value = false;
    toast(`${form.name} created. Owner signs in with school code "${form.slug}" and phone ${phone}.`);
  } catch (e) { error.value = (e as Error).message; } finally { busy.value = false; }
}
async function setStatus(s: Row, status: string) {
  if (status !== 'active' && !(await ask(`${status === 'suspended' ? 'Suspend' : 'Make read-only'}: ${s.name}?`,
    status === 'suspended' ? 'Nobody from this school can sign in until you activate it again.' : 'Staff can sign in and view data but cannot change anything.', 'Confirm', status === 'suspended'))) return;
  busy.value = true;
  await attempt(() => setSchoolStatus(s, status), `${s.name} is now ${STATUS[status].toLowerCase()}.`);
  busy.value = false;
}
async function signOut() { await logout(); router.replace('/login'); }
</script>

<style scoped>
.out { appearance: none; background: rgba(255, 255, 255, 0.14); border: 0; color: #fff; font: inherit; font-size: 0.85rem; font-weight: 600; padding: 8px 14px; min-height: 40px; border-radius: 10px; margin-right: 8px; cursor: pointer; }
</style>
