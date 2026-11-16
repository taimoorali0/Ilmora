<template>
  <ion-page>
    <PageHeader :title="school" />
    <ion-content>
      <div class="wrap">
        <h2 class="hello">Assalam-o-Alaikum, {{ firstName }}</h2>
        <p class="muted">{{ nice(today()) }} · {{ roles }}</p>

        <div v-if="access !== 'full'" class="notice warn">This device is read-only. Connect to the internet to continue editing.</div>

        <div class="stats">
          <div class="stat link" v-if="can('/families')" @click="$router.push('/families')"><b>{{ students.length }}</b><span>Active students</span><small>{{ all('families').length }} families</small></div>
          <div class="stat link" v-if="can('/attendance')" @click="$router.push('/attendance')"><b>{{ att.marked ? att.pct + '%' : '–' }}</b><span>Present today</span><small>{{ att.absent }} absent · {{ unmarked.length }} classes not marked</small></div>
          <div class="stat link" v-if="can('/fees')" @click="$router.push('/fees')"><b>{{ money(collectedThisMonth) }}</b><span>Collected in {{ niceMonth(period()) }}</span><small>{{ money(collectedToday) }} today</small></div>
          <div class="stat link" v-if="can('/fees')" @click="$router.push('/fees')"><b>{{ money(outstanding) }}</b><span>Outstanding fees</span><small>{{ owing }} families owe</small></div>
          <div class="stat link" v-if="can('/staff')" @click="$router.push('/staff')"><b>{{ staffIn }}/{{ staffTotal }}</b><span>Staff present today</span><small>{{ leaveRequests.length }} leave requests waiting</small></div>
        </div>

        <div class="cols">
          <div class="card" v-if="can('/attendance')">
            <h3>Attendance, last 7 school days</h3>
            <div class="chart" role="img" :aria-label="'Attendance percentage for the last school days'">
              <div v-for="d in week" :key="d.date" class="col">
                <span class="v">{{ d.pct }}%</span>
                <i :style="{ height: Math.max(3, d.pct * 0.72) + '%' }"></i>
                <span class="d">{{ nice(d.date).split(' ').slice(0, 2).join(' ') }}</span>
              </div>
            </div>
          </div>
          <div class="card" v-if="can('/fees')">
            <h3>Fee collection by month</h3>
            <div class="chart" role="img" aria-label="Fee collected in the last four months">
              <div v-for="m in months" :key="m.period" class="col">
                <span class="v">{{ Math.round(m.amount / 1000) }}k</span>
                <i :style="{ height: Math.max(3, (m.amount / maxMonth) * 72) + '%' }"></i>
                <span class="d">{{ niceMonth(m.period).split(' ')[0] }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="cols">
          <div class="card flush" v-if="todo.length">
            <h3>Needs attention</h3>
            <div class="list">
              <div v-for="t in todo" :key="t.text" class="item click" @click="$router.push(t.to)">
                <span class="pill" :class="t.kind">{{ t.count }}</span>
                <div class="grow"><h4>{{ t.text }}</h4><p>{{ t.hint }}</p></div>
              </div>
            </div>
          </div>
          <div class="card flush" v-if="can('/fees') && recent.length">
            <h3>Recent payments</h3>
            <div class="list">
              <div v-for="p in recent" :key="p.id" class="item click" @click="$router.push(`/receipt/${p.id}`)">
                <div class="grow"><h4>{{ find('families', p.family_id)?.primary_guardian_name }}</h4><p>{{ p.receipt_no }} · {{ p.method }} · {{ nice(p.received_at) }}</p></div>
                <b class="num">{{ money(p.amount) }}</b>
              </div>
            </div>
          </div>
          <div class="card flush" v-if="notices.length">
            <h3>Notices</h3>
            <div class="list">
              <div v-for="n in notices" :key="n.id" class="item"><div class="grow"><h4>{{ n.title }}</h4><p>{{ n.body }}</p></div></div>
            </div>
          </div>
        </div>

        <div class="card">
          <h3>Modules</h3>
          <div class="mods">
            <button v-for="n in nav" :key="n.path" class="mod" @click="$router.push(n.path)">
              <ion-icon :icon="n.icon" aria-hidden="true" /><b>{{ n.label }}</b><span>{{ n.hint }}</span>
            </button>
          </div>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { addDays, addMonths, localDay, money, nice, niceMonth, percent, period, today, weekday } from '@/lib/format';
import { allLedgers, livePayments } from '@/lib/logic/fees';
import { canOpen, myNav } from '@/lib/nav';
import { activeStudents, mySections, roleLabel, sectionStudents } from '@/lib/school';
import { effectiveAccess, hasRole, session } from '@/lib/session';
import { all, find, groupBy, where } from '@/lib/store';

const can = canOpen;
const school = computed(() => session.data?.user.school?.name ?? 'Ilmora');
const firstName = computed(() => session.data?.user.name ?? '');
const roles = computed(() => session.data?.user.roles.map((r) => roleLabel(r.role)).join(', ') ?? '');
const access = computed(() => effectiveAccess());
const nav = computed(() => myNav().filter((n) => n.path !== '/home'));
const students = computed(() => activeStudents());

const dayStats = (date: string) => {
  const rows = groupBy('student_attendance', 'date').get(date) ?? [];
  const present = rows.filter((r) => r.status === 'present' || r.status === 'late').length;
  return { marked: rows.length, absent: rows.filter((r) => r.status === 'absent').length, pct: percent(present, rows.length) };
};
const att = computed(() => dayStats(today()));
const unmarked = computed(() => {
  const done = new Set((groupBy('student_attendance', 'date').get(today()) ?? []).map((r) => r.section_id));
  return mySections().filter((s) => !done.has(s.id) && sectionStudents(s.id).length);
});
const week = computed(() => {
  const out: { date: string; pct: number }[] = [];
  for (let back = 0; out.length < 7 && back < 20; back++) {
    const date = addDays(today(), -back);
    const s = dayStats(date);
    if (weekday(date) !== 0 && s.marked) out.unshift({ date, pct: s.pct });
  }
  return out;
});

const payments = computed(() => livePayments());
const collectedThisMonth = computed(() => payments.value.filter((p) => localDay(p.received_at).startsWith(period())).reduce((s, p) => s + p.amount, 0));
const collectedToday = computed(() => payments.value.filter((p) => localDay(p.received_at) === today()).reduce((s, p) => s + p.amount, 0));
const outstanding = computed(() => [...allLedgers().values()].reduce((s, l) => s + Math.max(0, l.balance), 0));
const owing = computed(() => [...allLedgers().values()].filter((l) => l.balance > 0).length);
const months = computed(() => [3, 2, 1, 0].map((back) => {
  const p = addMonths(period(), -back);
  return { period: p, amount: payments.value.filter((x) => localDay(x.received_at).startsWith(p)).reduce((s, x) => s + x.amount, 0) };
}));
const maxMonth = computed(() => Math.max(1, ...months.value.map((m) => m.amount)));
const recent = computed(() => [...payments.value].sort((a, b) => String(b.received_at).localeCompare(String(a.received_at))).slice(0, 5));

const staffToday = computed(() => where('staff_attendance_days', { shift_date: today() }));
const staffTotal = computed(() => all('staff').filter((s) => s.status !== 'left').length);
const staffIn = computed(() => staffToday.value.filter((d) => ['present', 'late', 'half_day'].includes(d.status)).length);
const leaveRequests = computed(() => where('staff_leaves', { status: 'requested' }));
const notices = computed(() => [...all('notices')].sort((a, b) => String(b.published_on).localeCompare(String(a.published_on))).slice(0, 3));

const todo = computed(() => {
  const list: { count: number; text: string; hint: string; to: string; kind: string }[] = [];
  if (can('/attendance') && unmarked.value.length) list.push({ count: unmarked.value.length, text: 'Classes without attendance today', hint: 'Open attendance and mark them', to: '/attendance', kind: 'warn' });
  if (hasRole('owner', 'principal') && leaveRequests.value.length) list.push({ count: leaveRequests.value.length, text: 'Staff leave requests', hint: 'Approve or reject', to: '/staff', kind: 'info' });
  const papers = all('exam_papers');
  const submitted = papers.filter((p) => p.status === 'submitted').length;
  const reviewed = papers.filter((p) => p.status === 'reviewed').length;
  const returned = papers.filter((p) => p.status === 'returned' && p.author_id === session.data?.user.id).length;
  if (hasRole('examiner', 'owner') && submitted) list.push({ count: submitted, text: 'Exam papers waiting for review', hint: 'Examiner check', to: '/papers', kind: 'info' });
  if (hasRole('principal', 'owner') && reviewed) list.push({ count: reviewed, text: 'Exam papers waiting for final approval', hint: 'Principal approval', to: '/papers', kind: 'info' });
  if (hasRole('teacher') && returned) list.push({ count: returned, text: 'Exam papers returned to you', hint: 'Read the note and resubmit', to: '/papers', kind: 'bad' });
  const overdue = [...allLedgers().values()].filter((l) => l.overdue > 0).length;
  if (can('/fees') && overdue) list.push({ count: overdue, text: 'Families with overdue fees', hint: 'Send reminders from Fees', to: '/fees', kind: 'bad' });
  return list;
});
</script>

<style scoped>
.hello { margin: 4px 0 2px; font-size: 1.3rem; }
.hello + .muted { margin: 0 0 16px; }
.chart { display: flex; gap: 8px; align-items: flex-end; height: 150px; }
.col { flex: 1; min-width: 0; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 4px; }
.col i { display: block; width: 100%; max-width: 44px; background: var(--ilm-teal); border-radius: 6px 6px 2px 2px; }
.col .v { font-size: 0.75rem; font-weight: 600; }
.col .d { font-size: 0.75rem; color: var(--ilm-muted); white-space: nowrap; }
.mods { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 10px; }
.mod { appearance: none; text-align: left; font: inherit; color: inherit; background: var(--ilm-soft); border: 1px solid transparent; border-radius: 12px; padding: 12px; cursor: pointer; display: grid; grid-template-columns: auto 1fr; column-gap: 10px; align-items: center; }
.mod:hover, .mod:focus-visible { border-color: var(--ilm-teal); outline: none; }
.mod ion-icon { grid-row: span 2; font-size: 1.5rem; color: var(--ilm-teal); }
.mod b { font-size: 0.9rem; }
.mod span { font-size: 0.78rem; color: var(--ilm-muted); }
</style>
