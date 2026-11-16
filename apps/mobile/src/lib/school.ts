import { hasRole, session } from './session';
import { all, find, groupBy, mix, type Row } from './store';

/** Lookups shared by many screens. */
export const currentSession = (): Row | undefined => all('academic_sessions').find((s) => s.is_current) ?? all('academic_sessions')[0];

export const sortedClasses = (): Row[] => [...all('classes')].filter((c) => c.active !== false).sort((a, b) => a.sort_order - b.sort_order);

export function sectionLabel(sectionId: string | null | undefined): string {
  const sec = find('sections', sectionId);
  if (!sec) return '';
  return `${className(sec.class_id)} – ${sec.name}`;
}

export function className(classId: string | null | undefined): string {
  const name = find('classes', classId)?.name ?? '';
  return /^\d+$/.test(name) ? `Class ${name}` : name;
}

export function sortedSections(): Row[] {
  const order = new Map(all('classes').map((c) => [c.id, c.sort_order]));
  const sid = currentSession()?.id;
  return all('sections').filter((s) => !sid || s.academic_session_id === sid)
    .sort((a, b) => (order.get(a.class_id) ?? 0) - (order.get(b.class_id) ?? 0) || a.name.localeCompare(b.name));
}

/** The student's enrollment in the current session. */
export function enrollmentOf(studentId: string): Row | undefined {
  const sid = currentSession()?.id;
  const list = groupBy('enrollments', 'student_id').get(studentId) ?? [];
  return list.find((e) => e.academic_session_id === sid && e.status === 'active') ?? list[list.length - 1];
}

export const studentSection = (studentId: string) => sectionLabel(enrollmentOf(studentId)?.section_id);

/** Active students of a section, in roll number order. */
export function sectionStudents(sectionId: string): (Row & { roll_no: string })[] {
  return (groupBy('enrollments', 'section_id').get(sectionId) ?? [])
    .filter((e) => e.status === 'active')
    .map((e) => mix((find('students', e.student_id) ?? { id: '' }) as Row, { roll_no: e.roll_no as string }))
    .filter((s) => s.id && s.status === 'active')
    .sort((a, b) => Number(a.roll_no) - Number(b.roll_no) || a.name.localeCompare(b.name));
}

export const activeStudents = (): Row[] => all('students').filter((s) => s.status === 'active');
export const subjectName = (id: string | null | undefined) => find('subjects', id)?.name ?? '';
export const staffName = (id: string | null | undefined) => find('staff', id)?.name ?? '';


const MANAGERS = ['owner', 'principal', 'admin', 'accountant', 'examiner'];
/** A teacher sees the sections they teach; office roles see every section. */
export function mySections(): Row[] {
  const sections = sortedSections();
  if (hasRole(...MANAGERS)) return sections;
  const staffId = session.data?.user.staff_id;
  const taught = new Set(all('section_subjects').filter((x) => x.teacher_id === staffId).map((x) => x.section_id));
  return sections.filter((s) => s.class_teacher_id === staffId || taught.has(s.id));
}
export const isManager = () => hasRole('owner', 'principal', 'admin');
export const roleLabel = (role: string) => ({ owner: 'Owner', principal: 'Principal', admin: 'Admin officer', accountant: 'Accountant', examiner: 'Examiner', teacher: 'Teacher', parent: 'Parent' } as Record<string, string>)[role] ?? role;
export const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('');
