import { daysInMonth, weekday } from '../format';
import { all, find, insert, update, where, type Row } from '../store';

/**
 * Payroll on the device, the same rules as App\Domain\Payroll\PayrollCalculator
 * (docs/decisions.md D5). With a server, approval always recalculates there.
 */
export interface PayLine { name: string; type: 'earning' | 'deduction'; amount: number; basis?: string }
export type AttendanceSummary = Record<'present' | 'late' | 'half_day' | 'absent' | 'leave_unpaid' | 'leave_paid' | 'holiday' | 'weekly_off' | 'incomplete' | 'overtime_minutes', number>;

export const emptySummary = (): AttendanceSummary => ({ present: 0, late: 0, half_day: 0, absent: 0, leave_unpaid: 0, leave_paid: 0, holiday: 0, weekly_off: 0, incomplete: 0, overtime_minutes: 0 });

export function calculate(fixed: PayLine[], att: AttendanceSummary, policy: { day_basis: string; lates_per_day: number }, loan: number, workingDays: number) {
  if (att.incomplete > 0) throw new Error(`${att.incomplete} day(s) have incomplete punches; fix them before payroll.`);
  const lines: PayLine[] = [];
  let gross = 0;
  for (const l of fixed) if (l.type === 'earning') { gross += l.amount; lines.push({ ...l, basis: 'structure' }); }
  const basisDays = policy.day_basis === 'fixed_30' ? 30 : Math.max(1, workingDays);
  const lateDays = policy.lates_per_day > 0 ? Math.floor(att.late / policy.lates_per_day) : 0;
  const halfUnits = (att.absent + att.leave_unpaid + lateDays) * 2 + att.half_day;
  const attendanceDeduction = Math.floor((gross * halfUnits) / (basisDays * 2));
  let deductions = 0;
  if (attendanceDeduction > 0) {
    deductions += attendanceDeduction;
    lines.push({ name: 'Attendance deduction', type: 'deduction', amount: attendanceDeduction,
      basis: `${att.absent} absent, ${att.leave_unpaid} unpaid leave, ${att.half_day} half day, ${lateDays} late-days on ${basisDays}-day basis` });
  }
  for (const l of fixed) if (l.type === 'deduction') { deductions += l.amount; lines.push({ ...l, basis: 'structure' }); }
  if (loan > 0) { deductions += loan; lines.push({ name: 'Loan installment', type: 'deduction', amount: loan, basis: 'loan schedule' }); }
  return { gross, total_deductions: deductions, net: gross - deductions, lines };
}

export function workingDaysIn(periodYm: string): number {
  const holidays = new Set(all('holidays').filter((h) => h.for_staff !== false).map((h) => h.date));
  let n = 0;
  for (let d = 1; d <= daysInMonth(periodYm); d++) {
    const date = `${periodYm}-${String(d).padStart(2, '0')}`;
    if (weekday(date) !== 0 && !holidays.has(date)) n++;
  }
  return n;
}

export function attendanceSummary(staffId: string, periodYm: string): AttendanceSummary {
  const s = emptySummary();
  for (const day of where('staff_attendance_days', (r) => r.staff_id === staffId && String(r.shift_date).startsWith(periodYm))) {
    (s as Record<string, number>)[day.status] = ((s as Record<string, number>)[day.status] ?? 0) + 1;
    s.overtime_minutes += day.overtime_minutes ?? 0;
  }
  return s;
}

export const parseJson = <T>(v: unknown, fallback: T): T => (typeof v === 'string' ? (JSON.parse(v) as T) : (v as T) ?? fallback);

/** Demo only: build the draft payroll on this device. */
export function payrollDraftLocal(periodYm: string, branchId: string | null) {
  let run = all('payroll_runs').find((r) => r.period === periodYm && r.branch_id === branchId);
  if (run && ['approved', 'paid'].includes(run.status)) throw new Error('Payroll for this month is already approved.');
  run ??= insert('payroll_runs', { period: periodYm, status: 'draft', approved_by: null, approved_at: null, branch_id: branchId }, { local: true });
  const workingDays = workingDaysIn(periodYm);
  const blocked: string[] = [];
  let net = 0, payslips = 0;
  for (const s of all('staff').filter((x) => x.status !== 'left')) {
    const att = attendanceSummary(s.id, periodYm);
    const fixed: PayLine[] = [{ name: 'Basic salary', type: 'earning', amount: s.basic_salary ?? 0 }];
    if (s.allowances > 0) fixed.push({ name: 'Allowances', type: 'earning', amount: s.allowances });
    const loan = where('staff_loans', (l) => l.staff_id === s.id && l.status === 'active' && l.starts_period <= periodYm).reduce((t, l) => t + l.installment, 0);
    try {
      const slip = calculate(fixed, att, { day_basis: 'working_days', lates_per_day: 3 }, loan, workingDays);
      const values = { gross: slip.gross, total_deductions: slip.total_deductions, net: slip.net, lines: slip.lines, attendance_summary: att };
      const existing = all('payslips').find((p) => p.payroll_run_id === run!.id && p.staff_id === s.id);
      if (existing) update('payslips', existing.id, values, { local: true });
      else insert('payslips', { payroll_run_id: run.id, staff_id: s.id, payment_method: null, paid_at: null, branch_id: branchId, ...values }, { local: true });
      net += slip.net; payslips++;
    } catch (e) {
      blocked.push(`${s.name}: ${(e as Error).message}`);
    }
  }
  return { run_id: run.id as string, payslips, blocked, net };
}

export function payrollApproveLocal(runId: string, userId: string | null) {
  const run = find('payroll_runs', runId);
  if (!run) return;
  for (const d of where('staff_attendance_days', (r) => String(r.shift_date).startsWith(run.period))) update('staff_attendance_days', d.id, { locked: true }, { local: true });
  update('payroll_runs', runId, { status: 'approved', approved_by: userId, approved_at: new Date().toISOString() }, { local: true });
}

export function payrollPayLocal(runId: string) {
  for (const p of where('payslips', { payroll_run_id: runId } as Partial<Row>)) update('payslips', p.id, { paid_at: new Date().toISOString(), payment_method: 'cash' }, { local: true });
  update('payroll_runs', runId, { status: 'paid' }, { local: true });
}
