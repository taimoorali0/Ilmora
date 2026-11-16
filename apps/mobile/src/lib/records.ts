import { sectionStudents } from './school';
import { session } from './session';
import { all, insert, nextCounter, type Row } from './store';

/**
 * Creating families and students. Codes carry the device code so two offline
 * devices never hand out the same family code or admission number (D4).
 */
const device = () => session.data?.device.code ?? 'D00';

function freeCode(table: 'families' | 'students', column: string, prefix: string, counter: string): string {
  for (;;) {
    const code = `${prefix}-${device()}-${String(nextCounter(counter)).padStart(4, '0')}`;
    if (!all(table).some((r) => r[column] === code)) return code;
  }
}

export function newFamily(values: Record<string, any>): Row {
  const family = insert('families', { code: freeCode('families', 'code', 'FAM', 'family'), ...values });
  insert('guardians', { family_id: family.id, name: values.primary_guardian_name, relation: 'father', phone: values.primary_phone, cnic: values.primary_guardian_cnic ?? null, occupation: null });
  return family;
}

export function nextRoll(sectionId: string): string {
  return String(sectionStudents(sectionId).reduce((m, s) => Math.max(m, Number(s.roll_no) || 0), 0) + 1);
}

export function admitStudent(familyId: string, values: Record<string, any>, sectionId: string): Row {
  const section = all('sections').find((s) => s.id === sectionId);
  const student = insert('students', { family_id: familyId, admission_no: freeCode('students', 'admission_no', 'ADM', 'admission'), b_form: null, photo_path: null,
    status: 'active', left_on: null, notes: null, ...values });
  insert('enrollments', { student_id: student.id, academic_session_id: section?.academic_session_id, section_id: sectionId, roll_no: nextRoll(sectionId), status: 'active' });
  return student;
}
