import { insert, raw, uuid, type Row } from './store';

/**
 * Creates a school on this device with its starter setup: the demo counterpart of
 * App\Services\Platform\SchoolProvisioner. Only the super admin calls this (D10).
 */
export interface NewSchool {
  name: string; slug: string; phone?: string; address?: string; branch_name?: string;
  owner_name: string; owner_phone: string; owner_password: string; plan_id: string;
}

export const SUBJECT_NAMES = ['English', 'Urdu', 'Mathematics', 'Science', 'Islamiyat'];
export const CLASS_NAMES = ['Play Group', 'Nursery', 'Prep', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];

export function provisionSchool(d: NewSchool, today: string): { school: Row; branch: Row; owner: Row } {
  const slug = d.slug.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  if (!slug) throw new Error('Enter a school code.');
  if (raw('schools').some((s) => s.slug === slug)) throw new Error('This school code is already taken.');
  const school = insert('schools', { name: d.name.trim(), slug, phone: d.phone ?? null, email: null, address: d.address ?? null,
    logo_path: null, timezone: 'Asia/Karachi', currency: 'PKR', status: 'active', offline_access_days: 7 });
  const sid = { school_id: school.id };
  const branch = insert('branches', { ...sid, name: d.branch_name?.trim() || 'Main Campus', code: 'B1', active: true });
  const owner = insert('users', { ...sid, name: d.owner_name.trim(), phone: d.owner_phone.trim(), email: null,
    password: d.owner_password, is_super_admin: false, status: 'active', staff_id: null, family_id: null });
  insert('user_roles', { ...sid, user_id: owner.id, role: 'owner', branch_id: null });
  const year = Number(today.slice(5, 7)) >= 4 ? Number(today.slice(0, 4)) : Number(today.slice(0, 4)) - 1;
  insert('subscriptions', { ...sid, plan_id: d.plan_id, starts_on: today, ends_on: `${Number(today.slice(0, 4)) + 1}${today.slice(4)}`, grace_days: 7, amount: 0, status: 'active' });
  const session = insert('academic_sessions', { ...sid, name: `${year}-${String(year + 1).slice(2)}`, starts_on: `${year}-04-01`, ends_on: `${year + 1}-03-31`, is_current: true, status: 'open' });
  const b = { ...sid, branch_id: branch.id };
  // One section and the core subjects per class, so admissions and exams work on day one.
  const subjects = SUBJECT_NAMES.map((name) => insert('subjects', { ...sid, name, code: name.slice(0, 3).toUpperCase() }));
  CLASS_NAMES.forEach((name, i) => {
    const c = insert('classes', { ...b, name, sort_order: i + 1, active: true });
    const section = insert('sections', { ...b, class_id: c.id, academic_session_id: session.id, name: 'A', class_teacher_id: null, capacity: 30 });
    for (const sub of subjects) insert('section_subjects', { ...b, section_id: section.id, subject_id: sub.id, teacher_id: null });
  });
  for (const [name, frequency] of [['Tuition fee', 'monthly'], ['Admission fee', 'one_time'], ['Annual charges', 'yearly'], ['Exam fee', 'per_term']]) {
    insert('fee_heads', { ...sid, name, frequency, discountable: name === 'Tuition fee', active: true });
  }
  insert('discount_rules', { ...sid, name: 'Sibling discount', kind: 'sibling', priority: 1, config: { 2: 10, 3: 15, '4+': 20 }, max_total_percent: 50, active: true });
  insert('fine_rules', { ...sid, kind: 'fixed', amount: 200, cap: null, grace_days: 0, active: true });
  for (const [name, quota, paid] of [['Casual', 10, true], ['Sick', 8, true], ['Unpaid', 0, false]] as const) {
    insert('leave_types', { ...sid, name, yearly_quota: quota, paid });
  }
  insert('shifts', { ...b, name: 'Morning', starts_at: '07:45', ends_at: '14:00', grace_minutes: 10, half_day_after_minutes: 90,
    early_leave_grace_minutes: 10, window_before_minutes: 120, window_after_minutes: 240, weekly_offs: [0] });
  return { school, branch, owner };
}

export { uuid };
