<template>
  <ion-page>
    <ion-header>
      <ion-toolbar color="dark">
        <ion-title>{{ school }}</ion-title>
        <ion-buttons slot="end"><button class="out" @click="signOut">Sign out</button></ion-buttons>
      </ion-toolbar>
    </ion-header>
    <ion-content>
      <div class="wrap narrow">
        <h2 class="hello">Assalam-o-Alaikum, {{ session.data?.user.name }}</h2>
        <p class="muted" style="margin:0 0 16px">Parent app · {{ family?.code }}</p>

        <div class="card fee" :class="{ due: ledger.balance > 0 }">
          <div class="row spread">
            <div><span class="muted">{{ ledger.balance > 0 ? 'Fee due' : 'Fee status' }}</span><b class="big">{{ ledger.balance > 0 ? money(ledger.balance) : 'All paid' }}</b></div>
            <button v-if="latest" class="btn primary" @click="$router.push(`/voucher/${latest.invoice.id}`)">View voucher</button>
          </div>
          <p v-if="ledger.overdue > 0" class="error">{{ money(ledger.overdue) }} is past the due date.</p>
        </div>

        <div v-for="c in children" :key="c.student.id" class="card">
          <div class="row">
            <span class="avatar">{{ initials(c.student.name) }}</span>
            <div class="grow"><h3 style="margin:0">{{ c.student.name }}</h3><p class="muted" style="margin:2px 0 0">{{ c.section }} · Roll {{ c.roll }}</p></div>
            <span class="pill" :class="TODAY[c.today]?.kind">{{ TODAY[c.today]?.label ?? 'Not marked today' }}</span>
          </div>
          <div class="stats mini">
            <div class="stat"><b>{{ c.pct }}%</b><span>Attendance this month</span></div>
            <div class="stat"><b>{{ c.absent }}</b><span>Absent</span></div>
            <div class="stat"><b>{{ c.result ? c.result.pct + '%' : '–' }}</b><span>{{ c.exam?.name ?? 'No result yet' }}</span></div>
          </div>
          <button v-if="c.exam" class="btn sm" @click="$router.push(`/result/${c.exam.id}/${c.student.id}`)">Open result card</button>
          <div v-if="c.homework.length" class="hw">
            <h4>Homework and diary</h4>
            <div v-for="h in c.homework" :key="h.id" class="hwi"><b>{{ subjectName(h.subject_id) }}: {{ h.title }}</b><span>{{ h.body }}</span><small>{{ h.due_on ? 'Due ' + nice(h.due_on) : nice(h.assigned_on) }}</small></div>
          </div>
        </div>
        <p v-if="!children.length" class="empty">No children are linked to this login yet.</p>

        <div class="card flush" v-if="ledger.invoices.length">
          <h3>Fee vouchers</h3>
          <div class="list">
            <div v-for="s in [...ledger.invoices].reverse().slice(0, 6)" :key="s.invoice.id" class="item click" @click="$router.push(`/voucher/${s.invoice.id}`)">
              <div class="grow"><h4>{{ niceMonth(s.invoice.period) }}</h4><p>Due {{ nice(s.invoice.due_date) }} · {{ money(s.total) }}</p></div>
              <span class="pill" :class="s.status === 'paid' ? 'ok' : s.overdue ? 'bad' : 'warn'">{{ s.status === 'paid' ? 'Paid' : s.overdue ? 'Overdue' : s.status === 'partial' ? 'Partly paid' : 'Unpaid' }}</span>
            </div>
          </div>
        </div>
        <div class="card flush" v-if="payments.length">
          <h3>Payments</h3>
          <div class="list">
            <div v-for="p in payments" :key="p.id" class="item click" @click="$router.push(`/receipt/${p.id}`)">
              <div class="grow"><h4>{{ money(p.amount) }}</h4><p>{{ nice(p.received_at) }} · {{ p.receipt_no }}</p></div>
            </div>
          </div>
        </div>
        <div class="card flush" v-if="notices.length">
          <h3>Notices</h3>
          <div class="list"><div v-for="n in notices" :key="n.id" class="item"><div class="grow"><h4>{{ n.title }}</h4><p>{{ n.body }}</p><p>{{ nice(n.published_on) }}</p></div></div></div>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { logout } from '@/lib/auth';
import { money, nice, niceMonth, percent, period, today } from '@/lib/format';
import { examResult, examsSorted } from '@/lib/logic/exams';
import { familyLedger, livePayments } from '@/lib/logic/fees';
import { enrollmentOf, initials, sectionLabel, subjectName } from '@/lib/school';
import { session } from '@/lib/session';
import { all, find, groupBy } from '@/lib/store';
import { ask } from '@/lib/ui';

const TODAY: Record<string, { label: string; kind: string }> = { present: { label: 'Present today', kind: 'ok' }, late: { label: 'Late today', kind: 'warn' }, absent: { label: 'Absent today', kind: 'bad' }, leave: { label: 'On leave today', kind: 'info' } };
const router = useRouter();
const school = computed(() => session.data?.user.school?.name ?? 'Ilmora');
const familyId = computed(() => session.data?.user.family_id ?? '');
const family = computed(() => find('families', familyId.value));
const ledger = computed(() => familyLedger(familyId.value));
const latest = computed(() => [...ledger.value.invoices].reverse().find((s) => s.due > 0) ?? ledger.value.invoices[ledger.value.invoices.length - 1]);
const payments = computed(() => [...livePayments(familyId.value)].sort((a, b) => String(b.received_at).localeCompare(String(a.received_at))).slice(0, 6));
const notices = computed(() => all('notices').filter((n) => n.audience !== 'staff').sort((a, b) => String(b.published_on).localeCompare(String(a.published_on))).slice(0, 5));
const published = computed(() => examsSorted().filter((e) => e.status === 'published'));

const children = computed(() => (groupBy('students', 'family_id').get(familyId.value) ?? []).filter((s) => s.status === 'active').map((student) => {
  const e = enrollmentOf(student.id);
  const rows = (groupBy('student_attendance', 'student_id').get(student.id) ?? []);
  const month = rows.filter((r) => String(r.date).startsWith(period()));
  const exam = published.value.find((x) => examResult(x.id, student.id).subjects > 0);
  return {
    student, section: sectionLabel(e?.section_id), roll: e?.roll_no ?? '–',
    today: rows.find((r) => r.date === today())?.status ?? '',
    pct: percent(month.filter((r) => r.status === 'present' || r.status === 'late').length, month.length),
    absent: month.filter((r) => r.status === 'absent').length,
    exam, result: exam ? examResult(exam.id, student.id) : null,
    homework: all('homework').filter((h) => h.section_id === e?.section_id).sort((a, b) => String(b.assigned_on).localeCompare(String(a.assigned_on))).slice(0, 4),
  };
}));

async function signOut() {
  if (await ask('Sign out?', 'You will need your phone number and password to sign in again.', 'Sign out')) { await logout(); router.replace('/login'); }
}
</script>

<style scoped>
.out { appearance: none; background: rgba(255, 255, 255, 0.14); border: 0; color: #fff; font: inherit; font-size: 0.85rem; font-weight: 600; padding: 8px 14px; min-height: 40px; border-radius: 10px; margin-right: 8px; cursor: pointer; }
.hello { margin: 4px 0 2px; font-size: 1.25rem; }
.fee .big { display: block; font-size: 1.6rem; color: var(--ilm-ok); }
.fee.due .big { color: var(--ilm-bad); }
.stats.mini { grid-template-columns: repeat(3, 1fr); margin: 14px 0 12px; }
.stats.mini .stat { padding: 10px 12px; background: var(--ilm-soft); border: 0; }
.stats.mini b { font-size: 1.15rem; }
.hw { margin-top: 14px; border-top: 1px solid var(--ilm-line); padding-top: 12px; }
.hw h4 { margin: 0 0 8px; font-size: 0.9rem; }
.hwi { display: flex; flex-direction: column; gap: 2px; margin-bottom: 10px; font-size: 0.88rem; }
.hwi small { color: var(--ilm-muted); font-size: 0.78rem; }
</style>
