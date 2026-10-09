import { column, Schema, Table } from '@powersync/web';

/**
 * Local SQLite schema (subset of the server tables a device may receive).
 * `version` is the server version the device last saw; it is sent back as
 * base_version on upload so the server can detect conflicts (docs/decisions.md D2).
 * Money is integer rupees.
 */
const syncable = {
  school_id: column.text,
  branch_id: column.text,
  version: column.integer,
  created_at: column.text,
  updated_at: column.text,
};

const schools = new Table({
  name: column.text, slug: column.text, status: column.text, logo_path: column.text, settings: column.text,
});

const branches = new Table({ school_id: column.text, name: column.text, code: column.text, version: column.integer });

const user_roles = new Table({ school_id: column.text, user_id: column.text, role: column.text, branch_id: column.text });

const families = new Table({
  ...syncable, code: column.text, primary_guardian_name: column.text, primary_phone: column.text,
  whatsapp_phone: column.text, address: column.text,
}, { indexes: { code: ['code'] } });

const students = new Table({
  ...syncable, family_id: column.text, admission_no: column.text, name: column.text, gender: column.text,
  date_of_birth: column.text, admission_date: column.text, status: column.text,
}, { indexes: { family: ['family_id'] } });

const enrollments = new Table({
  ...syncable, student_id: column.text, academic_session_id: column.text, section_id: column.text,
  roll_no: column.text, status: column.text,
}, { indexes: { section: ['section_id'] } });

const section_subjects = new Table({
  ...syncable, section_id: column.text, subject_id: column.text, teacher_id: column.text,
});

const student_attendance = new Table({
  ...syncable, student_id: column.text, section_id: column.text, date: column.text, status: column.text,
  remark: column.text,
}, { indexes: { section_date: ['section_id', 'date'], student_date: ['student_id', 'date'] } });

const invoices = new Table({
  ...syncable, family_id: column.text, number: column.text, period: column.text, issue_date: column.text,
  due_date: column.text, status: column.text,
}, { indexes: { family: ['family_id'] } });

// Append-only on the server; created offline with a device receipt number (D3, D4).
const payments = new Table({
  school_id: column.text, branch_id: column.text, family_id: column.text, receipt_no: column.text,
  amount: column.integer, method: column.text, reference: column.text, received_at: column.text,
  received_by: column.text, sync_state: column.text, created_at: column.text,
});

const staff_attendance_days = new Table({
  ...syncable, staff_id: column.text, shift_date: column.text, check_in: column.text, check_out: column.text,
  status: column.text, late_minutes: column.integer,
});

const payslips = new Table({
  ...syncable, payroll_run_id: column.text, staff_id: column.text, gross: column.integer,
  total_deductions: column.integer, net: column.integer, lines: column.text, paid_at: column.text,
});

// Local only: next receipt sequence for this device (never synced).
const local_counters = new Table({ value: column.integer }, { localOnly: true });

export const AppSchema = new Schema({
  schools, branches, user_roles, families, students, enrollments, section_subjects, student_attendance,
  invoices, payments, staff_attendance_days, payslips, local_counters,
});

export type Database = (typeof AppSchema)['types'];
