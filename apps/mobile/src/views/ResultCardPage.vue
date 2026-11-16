<template>
  <ion-page>
    <PageHeader title="Result card" back="/exams" />
    <ion-content>
      <div class="wrap" v-if="exam && student">
        <div class="row acts no-print">
          <button class="btn" @click="printPage"><ion-icon :icon="printOutline" /> Print</button>
          <a v-if="family" class="btn wa" :href="waLink(family.whatsapp_phone || family.primary_phone, text)" target="_blank" rel="noopener"><ion-icon :icon="logoWhatsapp" /> Send on WhatsApp</a>
        </div>
        <div class="sheet">
          <Letterhead />
          <h3 class="doc">Result card · {{ exam.name }}</h3>
          <div class="two">
            <dl class="kv"><dt>Student</dt><dd><b>{{ student.name }}</b></dd><dt>Father / guardian</dt><dd>{{ family?.primary_guardian_name }}</dd><dt>Admission no</dt><dd>{{ student.admission_no }}</dd></dl>
            <dl class="kv"><dt>Class</dt><dd>{{ sectionLabel(enrollment?.section_id) }}</dd><dt>Roll no</dt><dd>{{ enrollment?.roll_no }}</dd><dt>Position</dt><dd>{{ position ? `${position} of ${classSize}` : '–' }}</dd></dl>
          </div>
          <table class="tbl">
            <thead><tr><th>Subject</th><th class="right">Total</th><th class="right">Pass</th><th class="right">Obtained</th><th class="center">Grade</th><th class="center">Result</th></tr></thead>
            <tbody>
              <tr v-for="l in result.lines" :key="l.subject_id">
                <td>{{ subjectName(l.subject_id) }}</td><td class="right num">{{ l.max }}</td><td class="right num">{{ l.pass }}</td>
                <td class="right num"><b>{{ l.absent ? 'Absent' : l.obtained }}</b></td><td class="center">{{ l.absent ? '–' : grade(percent(l.obtained ?? 0, l.max)) }}</td><td class="center">{{ l.passed ? 'Pass' : 'Fail' }}</td>
              </tr>
            </tbody>
            <tfoot><tr><td>Total</td><td class="right num">{{ result.total }}</td><td></td><td class="right num">{{ result.obtained }}</td><td class="center">{{ result.grade }}</td><td class="center">{{ result.passed ? 'Pass' : 'Fail' }}</td></tr></tfoot>
          </table>
          <div class="summary">
            <div><span>Percentage</span><b>{{ result.pct }}%</b></div>
            <div><span>Grade</span><b>{{ result.grade }}</b></div>
            <div><span>Attendance</span><b>{{ attendance }}%</b></div>
          </div>
          <p class="muted"><b>Remarks:</b> {{ remark }}</p>
          <div class="signs"><span>Class teacher</span><span>Principal</span><span>Parent</span></div>
        </div>
        <p v-if="!result.subjects" class="empty">No marks entered for this student in this exam.</p>
      </div>
      <p v-else class="empty">This result is not on this device.</p>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { logoWhatsapp, printOutline } from 'ionicons/icons';
import { computed } from 'vue';
import { grade, percent } from '@/lib/format';
import { classPositions, examResult } from '@/lib/logic/exams';
import { templates, waLink } from '@/lib/logic/messages';
import { enrollmentOf, sectionLabel, sectionStudents, subjectName } from '@/lib/school';
import { find, groupBy } from '@/lib/store';
import { printPage } from '@/lib/ui';

const props = defineProps<{ examId: string; studentId: string }>();
const exam = computed(() => find('exams', props.examId));
const student = computed(() => find('students', props.studentId));
const family = computed(() => find('families', student.value?.family_id));
const enrollment = computed(() => enrollmentOf(props.studentId));
const result = computed(() => examResult(props.examId, props.studentId));
const position = computed(() => (enrollment.value ? classPositions(props.examId, enrollment.value.section_id).get(props.studentId) : undefined));
const classSize = computed(() => (enrollment.value ? sectionStudents(enrollment.value.section_id).length : 0));
const attendance = computed(() => {
  const rows = groupBy('student_attendance', 'student_id').get(props.studentId) ?? [];
  return percent(rows.filter((r) => r.status === 'present' || r.status === 'late').length, rows.length);
});
const remark = computed(() => {
  const p = result.value.pct;
  if (!result.value.passed) return 'Needs extra attention and regular revision at home.';
  return p >= 85 ? 'Excellent performance. Keep it up.' : p >= 70 ? 'Very good. Can do even better with regular practice.' : p >= 50 ? 'Satisfactory. More practice is needed.' : 'Passed. Needs to work harder.';
});
const text = computed(() => (family.value && student.value && exam.value ? templates.result(family.value, student.value, exam.value.name, result.value.obtained, result.value.total, result.value.pct) : ''));
</script>

<style scoped>
.acts { margin-bottom: 14px; justify-content: center; }
.two { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px 24px; margin-bottom: 16px; }
.summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 16px 0; }
.summary div { background: #eef7f6; border-radius: 10px; padding: 10px; text-align: center; }
.summary span { display: block; font-size: 0.75rem; color: #555; }
.summary b { font-size: 1.2rem; color: #071a3a; }
</style>
