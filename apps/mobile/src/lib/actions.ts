import { api } from './api';
import { refreshPlatform } from './auth';
import { today } from './format';
import { voucherRunLocal } from './logic/fees';
import { payrollApproveLocal, payrollDraftLocal, payrollPayLocal } from './logic/payroll';
import { provisionSchool, type NewSchool } from './provision';
import { defaultBranchId, IS_DEMO, schoolId, session } from './session';
import { insert, raw, update, type Row } from './store';
import { pull } from './sync';

/**
 * Actions that need the whole, synchronized data set (Finalize level, D1).
 * With a server they run there and the result is pulled; the demo runs them here.
 */
export async function voucherRun(periodYm: string): Promise<{ created: number; skipped: number; total: number }> {
  if (IS_DEMO) return voucherRunLocal(periodYm);
  const res = await api<{ created: number; skipped: number; total: number }>('/finance/voucher-run', { method: 'POST', json: { period: periodYm, branch_id: defaultBranchId() } });
  await pull();
  return res;
}

export async function payrollDraft(periodYm: string): Promise<{ run_id: string; payslips: number; blocked: string[]; net: number }> {
  if (IS_DEMO) return payrollDraftLocal(periodYm, defaultBranchId());
  const res = await api<{ run_id: string; payslips: number; blocked: string[]; net: number }>('/payroll/draft', { method: 'POST', json: { period: periodYm, branch_id: defaultBranchId() } });
  await pull();
  return res;
}

export async function payrollApprove(runId: string): Promise<void> {
  if (IS_DEMO) return payrollApproveLocal(runId, session.data?.user.id ?? null);
  await api(`/payroll/${runId}/approve`, { method: 'POST' });
  await pull();
}

export async function payrollPay(runId: string): Promise<void> {
  if (IS_DEMO) return payrollPayLocal(runId);
  await api(`/payroll/${runId}/pay`, { method: 'POST' });
  await pull();
}

export async function decideLeave(leaveId: string, status: 'approved' | 'rejected'): Promise<void> {
  if (IS_DEMO) { update('staff_leaves', leaveId, { status, decided_by: session.data?.user.id, decided_at: new Date().toISOString() }); return; }
  await api(`/staff-leaves/${leaveId}/decide`, { method: 'POST', json: { status } });
  await pull();
}

export interface UserForm { id?: string; name: string; phone: string; password?: string; role: string; status?: string; staff_id?: string | null; family_id?: string | null }

export async function saveUser(f: UserForm): Promise<void> {
  if (!IS_DEMO) {
    await api('/users', { method: 'POST', json: { ...f, password: f.password || undefined } });
    await pull();
    return;
  }
  const sid = schoolId();
  if (raw('users').some((u) => u.school_id === sid && u.phone === f.phone && u.id !== f.id)) throw new Error('This phone number already has a login.');
  const values = { name: f.name, phone: f.phone, status: f.status ?? 'active', staff_id: f.staff_id ?? null, family_id: f.family_id ?? null };
  let id = f.id;
  if (id) update('users', id, f.password ? { ...values, password: f.password } : values);
  else id = insert('users', { ...values, email: null, password: f.password, is_super_admin: false }).id;
  const role = raw('user_roles').find((r) => r.user_id === id);
  if (role) update('user_roles', role.id, { role: f.role });
  else insert('user_roles', { user_id: id, role: f.role, branch_id: null });
}

// ---------- platform (super admin) ----------

export async function createSchool(d: NewSchool): Promise<void> {
  if (IS_DEMO) { provisionSchool(d, today()); return; }
  await api('/platform/schools', { method: 'POST', json: d });
  await refreshPlatform();
}

export async function setSchoolStatus(school: Row, status: string): Promise<void> {
  if (IS_DEMO) { update('schools', school.id, { status }); return; }
  await api(`/platform/schools/${school.id}`, { method: 'PATCH', json: { status } });
  await refreshPlatform();
}
