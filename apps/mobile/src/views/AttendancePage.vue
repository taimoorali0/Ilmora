<template>
  <ion-page>
    <PageHeader title="Attendance" />
    <ion-content>
      <div class="wrap narrow">
        <div class="row filters">
          <label class="field grow"><span>Class</span>
            <select v-model="sectionId"><option v-for="s in sections" :key="s.id" :value="s.id">{{ sectionLabel(s.id) }}</option></select>
          </label>
          <label class="field"><span>Date</span><input v-model="date" type="date" :max="today()" /></label>
        </div>

        <p v-if="!sections.length" class="empty">No classes are assigned to you yet.</p>
        <template v-else>
          <div v-if="dayOff" class="notice warn">{{ dayOff }}. Attendance is not needed for this day.</div>
          <div v-if="readOnly" class="notice warn">This device is read-only. Connect to the internet to keep marking attendance.</div>

          <div class="stats mini">
            <div class="stat"><b>{{ count.present }}</b><span>Present</span></div>
            <div class="stat"><b>{{ count.absent }}</b><span>Absent</span></div>
            <div class="stat"><b>{{ count.leave }}</b><span>Leave</span></div>
            <div class="stat"><b>{{ count.late }}</b><span>Late</span></div>
            <div class="stat"><b>{{ rows.length - marked }}</b><span>Not marked</span></div>
          </div>

          <div class="row acts">
            <button class="btn primary" :disabled="readOnly || marked === rows.length" @click="markAll">Mark the rest present</button>
            <button class="btn wa" :disabled="!count.absent" @click="notify"><ion-icon :icon="logoWhatsapp" /> Message parents of absent students ({{ count.absent }})</button>
            <button class="btn" @click="$router.push({ path: '/attendance/report', query: { section: sectionId } })">Monthly sheet</button>
          </div>

          <div class="card flush">
            <div class="list">
              <div v-for="r in rows" :key="r.id" class="item">
                <span class="roll">{{ r.roll_no }}</span>
                <div class="grow"><h4>{{ r.name }}</h4></div>
                <div class="marks" role="group" :aria-label="`Attendance for ${r.name}`">
                  <button v-for="o in OPTIONS" :key="o.value" :class="[o.value, { on: r.status === o.value }]" :disabled="readOnly" :aria-pressed="r.status === o.value" :title="o.label" @click="mark(r.id, o.value)">{{ o.short }}</button>
                </div>
              </div>
              <p v-if="!rows.length" class="empty">No students in this class yet.</p>
            </div>
          </div>
        </template>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { logoWhatsapp } from 'ionicons/icons';
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { nice, today, weekday } from '@/lib/format';
import { queueMessage, templates } from '@/lib/logic/messages';
import { mySections, sectionLabel, sectionStudents } from '@/lib/school';
import { canWrite } from '@/lib/session';
import { all, find, groupBy, mix, upsert } from '@/lib/store';
import { toast } from '@/lib/ui';

const OPTIONS = [{ value: 'present', short: 'P', label: 'Present' }, { value: 'absent', short: 'A', label: 'Absent' }, { value: 'leave', short: 'L', label: 'Leave' }, { value: 'late', short: 'Lt', label: 'Late' }];
const route = useRoute();
const sections = computed(() => mySections());
const sectionId = ref<string>('');
const date = ref(today());
watch([sections, () => route.query.section], () => {
  const wanted = String(route.query.section ?? '');
  if (wanted && sections.value.some((s) => s.id === wanted)) sectionId.value = wanted;
  else if (!sections.value.some((s) => s.id === sectionId.value)) sectionId.value = sections.value[0]?.id ?? '';
}, { immediate: true });

const readOnly = computed(() => !canWrite());
const dayOff = computed(() => {
  if (weekday(date.value) === 0) return 'Sunday';
  const h = all('holidays').find((x) => x.date === date.value && x.for_students !== false);
  return h ? `Holiday: ${h.name}` : '';
});
const byStudent = computed(() => new Map((groupBy('student_attendance', 'date').get(date.value) ?? []).map((r) => [r.student_id, r])));
const rows = computed(() => sectionStudents(sectionId.value).map((s) => mix(s, { status: byStudent.value.get(s.id)?.status as string | undefined })));
const marked = computed(() => rows.value.filter((r) => r.status).length);
const count = computed(() => {
  const c = { present: 0, absent: 0, leave: 0, late: 0 } as Record<string, number>;
  for (const r of rows.value) if (r.status) c[r.status]++;
  return c;
});

function mark(studentId: string, status: string) {
  if (readOnly.value) return;
  upsert('student_attendance', { student_id: studentId, date: date.value }, { section_id: sectionId.value, status, remark: null });
}
function markAll() {
  for (const r of rows.value) if (!r.status) mark(r.id, 'present');
  toast('Attendance saved.');
}
function notify() {
  let n = 0;
  for (const r of rows.value.filter((x) => x.status === 'absent')) {
    const family = find('families', r.family_id);
    if (family && queueMessage(family.id, 'absent', templates.absent(family, r, date.value))) n++;
  }
  toast(`${n} absence message${n === 1 ? '' : 's'} queued for ${nice(date.value)}.`);
}
</script>

<style scoped>
.filters { margin-bottom: 14px; align-items: flex-end; }
.acts { margin-bottom: 14px; }
.stats.mini { grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); }
.stats.mini .stat { padding: 10px 12px; }
.stats.mini b { font-size: 1.15rem; }
.roll { width: 30px; flex: none; text-align: center; color: var(--ilm-muted); font-weight: 600; font-size: 0.85rem; }
.marks { display: flex; gap: 4px; flex: none; }
.marks button { appearance: none; width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--ilm-line); background: var(--ilm-card); color: var(--ilm-muted); font: inherit; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
.marks button:focus-visible { outline: 2px solid var(--ilm-blue); outline-offset: 1px; }
.marks button.on.present { background: var(--ilm-ok-bg); color: var(--ilm-ok); border-color: var(--ilm-ok); }
.marks button.on.absent { background: var(--ilm-bad-bg); color: var(--ilm-bad); border-color: var(--ilm-bad); }
.marks button.on.leave { background: var(--ilm-info-bg); color: var(--ilm-info); border-color: var(--ilm-info); }
.marks button.on.late { background: var(--ilm-warn-bg); color: var(--ilm-warn); border-color: var(--ilm-warn); }
</style>
