<template>
  <ion-page>
    <PageHeader title="Attendance report" />
    <ion-content>
      <div class="wrap">
        <div class="row filters no-print">
          <label class="field grow"><span>Class</span><select v-model="sectionId"><option v-for="s in sections" :key="s.id" :value="s.id">{{ sectionLabel(s.id) }}</option></select></label>
          <label class="field"><span>Month</span><input v-model="month" type="month" :max="period()" /></label>
          <button class="btn" @click="printPage"><ion-icon :icon="printOutline" /> Print sheet</button>
          <button class="btn wa" :disabled="!rows.length" @click="send"><ion-icon :icon="logoWhatsapp" /> Send monthly report to parents</button>
        </div>

        <div class="sheet wide-sheet">
          <Letterhead />
          <h3 class="doc">Monthly attendance · {{ sectionLabel(sectionId) }} · {{ niceMonth(month) }}</h3>
          <div class="tbl-wrap">
            <table class="tbl grid">
              <thead>
                <tr><th>Roll</th><th>Student</th><th v-for="d in days" :key="d.date" class="center" :class="{ off: d.off }">{{ d.n }}</th><th class="center">P</th><th class="center">A</th><th class="center">L</th><th class="center">%</th></tr>
              </thead>
              <tbody>
                <tr v-for="r in rows" :key="r.id">
                  <td>{{ r.roll_no }}</td><td class="nowrap">{{ r.name }}</td>
                  <td v-for="(c, i) in r.cells" :key="i" class="center" :class="[c, { off: days[i].off }]">{{ LETTER[c] ?? '' }}</td>
                  <td class="center num">{{ r.present }}</td><td class="center num">{{ r.absent }}</td><td class="center num">{{ r.leave }}</td><td class="center num"><b>{{ r.pct }}%</b></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="!rows.length" class="empty">No students in this class.</p>
          <p class="muted">P present, A absent, L leave, Lt late (counted as present). Grey columns are Sundays and holidays.</p>
          <div class="signs"><span>Class teacher</span><span>Principal</span></div>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { logoWhatsapp, printOutline } from 'ionicons/icons';
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { daysInMonth, niceMonth, percent, period, weekday } from '@/lib/format';
import { queueMessage, templates } from '@/lib/logic/messages';
import { mySections, sectionLabel, sectionStudents } from '@/lib/school';
import { all, find, groupBy, mix } from '@/lib/store';
import { printPage, toast } from '@/lib/ui';

const LETTER: Record<string, string> = { present: 'P', absent: 'A', leave: 'L', late: 'Lt' };
const route = useRoute();
const sections = computed(() => mySections());
const sectionId = ref('');
const month = ref(period());
watch([sections, () => route.query.section], () => {
  const wanted = String(route.query.section ?? '');
  if (wanted && sections.value.some((s) => s.id === wanted)) sectionId.value = wanted;
  else if (!sections.value.some((s) => s.id === sectionId.value)) sectionId.value = sections.value[0]?.id ?? '';
}, { immediate: true });

const days = computed(() => {
  const holidays = new Set(all('holidays').filter((h) => h.for_students !== false).map((h) => h.date));
  return Array.from({ length: daysInMonth(month.value) }, (_, i) => {
    const date = `${month.value}-${String(i + 1).padStart(2, '0')}`;
    return { n: i + 1, date, off: weekday(date) === 0 || holidays.has(date) };
  });
});
const rows = computed(() => sectionStudents(sectionId.value).map((s) => {
  const by = new Map((groupBy('student_attendance', 'student_id').get(s.id) ?? []).filter((r) => String(r.date).startsWith(month.value)).map((r) => [r.date, r.status as string]));
  const cells = days.value.map((d) => by.get(d.date) ?? '');
  const present = cells.filter((c) => c === 'present' || c === 'late').length;
  const absent = cells.filter((c) => c === 'absent').length;
  const leave = cells.filter((c) => c === 'leave').length;
  return mix(s, { cells, present, absent, leave, pct: percent(present, present + absent + leave) });
}));

function send() {
  let n = 0;
  for (const r of rows.value) {
    const family = find('families', r.family_id);
    if (family && queueMessage(family.id, 'attendance_report', templates.attendance(family, r, month.value, r.present, r.absent, r.leave))) n++;
  }
  toast(`${n} attendance reports queued for parents.`);
}
</script>

<style scoped>
.filters { margin-bottom: 14px; align-items: flex-end; }
.wide-sheet { max-width: none; }
.grid { font-size: 0.75rem; }
.grid th, .grid td { padding: 5px 4px; border: 1px solid #ddd; }
.grid td.absent { color: #b3261e; font-weight: 700; }
.grid td.leave { color: #2f4bb8; }
.grid td.late { color: #8a5b0f; }
.grid .off { background: #eef0f3; }
</style>
