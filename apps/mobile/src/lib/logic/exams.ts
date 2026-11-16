import { grade, percent } from '../format';
import { enrollmentOf, sectionStudents } from '../school';
import { all, find, groupBy, type Row } from '../store';

/** Results worked out on the device from exam subjects and marks. */
export interface SubjectResult { subject_id: string; max: number; pass: number; obtained: number | null; absent: boolean; passed: boolean }
export interface Result { lines: SubjectResult[]; obtained: number; total: number; pct: number; grade: string; subjects: number; passed: boolean }

export function examSubjectsFor(examId: string, classId: string | null | undefined): Row[] {
  return (groupBy('exam_subjects', 'exam_id').get(examId) ?? []).filter((s) => s.class_id === classId);
}

export function markOf(examSubjectId: string, studentId: string): Row | undefined {
  return (groupBy('marks', 'exam_subject_id').get(examSubjectId) ?? []).find((m) => m.student_id === studentId);
}

export function examResult(examId: string, studentId: string): Result {
  const classId = find('sections', enrollmentOf(studentId)?.section_id)?.class_id;
  const lines: SubjectResult[] = [];
  for (const es of examSubjectsFor(examId, classId)) {
    const m = markOf(es.id, studentId);
    if (!m) continue;
    const obtained = m.absent ? null : Number(m.obtained ?? 0);
    lines.push({ subject_id: es.subject_id, max: es.max_marks, pass: es.pass_marks, obtained, absent: !!m.absent, passed: obtained !== null && obtained >= es.pass_marks });
  }
  const obtained = lines.reduce((s, l) => s + (l.obtained ?? 0), 0);
  const total = lines.reduce((s, l) => s + l.max, 0);
  const pct = percent(obtained, total);
  return { lines, obtained, total, pct, grade: grade(pct), subjects: lines.length, passed: lines.length > 0 && lines.every((l) => l.passed) };
}

/** Position in class by total marks (ties share a position). */
export function classPositions(examId: string, sectionId: string): Map<string, number> {
  const totals = sectionStudents(sectionId).map((s) => ({ id: s.id, r: examResult(examId, s.id) })).filter((x) => x.r.subjects > 0)
    .sort((a, b) => b.r.obtained - a.r.obtained);
  const out = new Map<string, number>();
  totals.forEach((t, i) => out.set(t.id, i > 0 && totals[i - 1].r.obtained === t.r.obtained ? out.get(totals[i - 1].id)! : i + 1));
  return out;
}

export const examsSorted = (): Row[] => [...all('exams')].sort((a, b) => String(b.starts_on).localeCompare(String(a.starts_on)));
