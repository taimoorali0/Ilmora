import { addDays, addMonths, daysInMonth, period, today as todayYmd, weekday } from './format';
import { voucherRunLocal } from './logic/fees';
import { payrollApproveLocal, payrollDraftLocal, payrollPayLocal } from './logic/payroll';
import { provisionSchool } from './provision';
import { session } from './session';
import { all, insert, raw, replaceAll, update, type Row, type TableName } from './store';

/**
 * Sample data for the demo: one busy school with four months of history, a second
 * empty school, and the platform super admin. Everything is invented.
 * The same data seeds the Laravel database (npm run demo:export, php artisan db:seed).
 */
export const DEMO_PASSWORD = 'password';
export const DEMO_LOGINS = [
  { role: 'Owner', name: 'Muhammad Usman', login: '03000000001', note: 'Everything' },
  { role: 'Principal', name: 'Saima Tariq', login: '03000000003', note: 'Approvals, academics, staff' },
  { role: 'Accountant', name: 'Bilal Ahmed', login: '03000000004', note: 'Fees, payroll, expenses' },
  { role: 'Admin officer', name: 'Rabia Aslam', login: '03000000005', note: 'Admissions, attendance, staff' },
  { role: 'Examiner', name: 'Kamran Akmal', login: '03000000006', note: 'Exams, paper review' },
  { role: 'Teacher', name: 'Hina Iqbal', login: '03000000002', note: 'Own classes: attendance, marks, homework' },
  { role: 'Parent', name: 'Imran Sheikh', login: '03000000007', note: 'Children, fees, results' },
];
export const DEMO_SUPER = { email: 'admin@ilmora.test', password: DEMO_PASSWORD };

function rng(seed: number) {
  return () => {
    seed |= 0; seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const BOYS = ['Ali', 'Ahmed', 'Hamza', 'Bilal', 'Usman', 'Hassan', 'Hussain', 'Zain', 'Saad', 'Ibrahim', 'Abdullah', 'Umar', 'Taha', 'Rayyan', 'Shayan', 'Daniyal', 'Arham', 'Faizan', 'Moiz', 'Haris'];
const GIRLS = ['Ayesha', 'Fatima', 'Zainab', 'Maryam', 'Hira', 'Amna', 'Khadija', 'Noor', 'Iqra', 'Sana', 'Laiba', 'Eman', 'Anaya', 'Hania', 'Zoya', 'Mahnoor', 'Aleena', 'Rida', 'Minahil', 'Areeba'];
const FATHERS = ['Imran', 'Tariq', 'Kashif', 'Nadeem', 'Asif', 'Shahid', 'Rizwan', 'Faisal', 'Waseem', 'Javed', 'Naveed', 'Adnan', 'Kamran', 'Sajid', 'Irfan', 'Zahid', 'Amir', 'Farhan', 'Salman', 'Yasir'];
const SURNAMES = ['Sheikh', 'Khan', 'Malik', 'Butt', 'Chaudhry', 'Qureshi', 'Hussain', 'Raza', 'Iqbal', 'Siddiqui', 'Mirza', 'Awan', 'Bhatti', 'Rana', 'Ansari', 'Gill'];
const AREAS = ['Johar Town', 'Model Town', 'Wapda Town', 'Township', 'Garden Town', 'Faisal Town', 'Iqbal Town', 'Valencia'];
const SUBJECTS = ['English', 'Urdu', 'Mathematics', 'Science', 'Islamiyat', 'Social Studies', 'Computer'];
const STAFF: [string, string, number, number][] = [
  ['Saima Tariq', 'Principal', 120000, 20000], ['Bilal Ahmed', 'Accountant', 60000, 5000], ['Rabia Aslam', 'Admin officer', 45000, 3000],
  ['Kamran Akmal', 'Examination coordinator', 65000, 5000], ['Hina Iqbal', 'Teacher', 48000, 4000], ['Sadia Noor', 'Teacher', 42000, 3000],
  ['Farah Naz', 'Teacher', 40000, 3000], ['Asma Javed', 'Teacher', 40000, 3000], ['Nida Rauf', 'Teacher', 38000, 3000],
  ['Uzma Khalid', 'Teacher', 38000, 3000], ['Shazia Parveen', 'Teacher', 36000, 2000], ['Mehwish Ali', 'Teacher', 36000, 2000],
  ['Tahir Mehmood', 'Teacher', 52000, 4000], ['Waqas Anwar', 'Teacher', 50000, 4000], ['Zubair Hassan', 'Teacher', 46000, 4000],
  ['Sobia Arif', 'Teacher', 35000, 2000], ['Nasreen Bibi', 'Teacher', 34000, 2000], ['Muhammad Aslam', 'Guard', 28000, 0],
];

export function seedDemo(today: string = todayYmd()): void {
  const r = rng(20261115);
  const pick = <T>(list: T[]) => list[Math.floor(r() * list.length)];
  const phone = () => '03' + String(Math.floor(r() * 50) + 0).padStart(2, '0') + String(Math.floor(r() * 9000000) + 1000000);
  const cnic = () => `35202-${String(Math.floor(r() * 9000000) + 1000000)}-${Math.floor(r() * 9) + 1}`;
  replaceAll({});

  // ----- platform -----
  const plans = [['Basic', 300, 30, 1, 5000], ['Standard', 1000, 100, 2, 12000], ['Premium', null, null, 5, 25000]].map(([name, students, staff, branches, price]) =>
    insert('plans', { name, max_students: students, max_staff: staff, max_branches: branches, monthly_message_credits: 2000,
      modules: ['students', 'attendance', 'fees', 'exams', 'exam_papers', 'staff', 'payroll', 'syllabus', 'worksheets', 'messaging', 'parent_app'],
      price_monthly: price, price_yearly: (price as number) * 10, active: true }));
  insert('users', { school_id: null, name: 'Ilmora Super Admin', email: DEMO_SUPER.email, phone: null, password: DEMO_PASSWORD,
    is_super_admin: true, status: 'active', staff_id: null, family_id: null });
  provisionSchool({ name: 'Al-Noor Grammar School', slug: 'alnoor', phone: '041-2600000', address: 'Peoples Colony, Faisalabad',
    owner_name: 'Ayesha Khan', owner_phone: '03111111111', owner_password: DEMO_PASSWORD, plan_id: plans[0].id }, today);
  const { school, branch, owner } = provisionSchool({ name: 'Demo Public School', slug: 'demo', phone: '042-35000000',
    address: '12-B, Johar Town, Lahore', owner_name: 'Muhammad Usman', owner_phone: '03000000001', owner_password: DEMO_PASSWORD, plan_id: plans[1].id }, today);

  // The fee and payroll logic reads the signed-in school, so work as the owner while seeding.
  session.data = {
    token: 'seed', access: 'full', device: { id: 'seed', code: 'D01', offline_valid_until: '2099-01-01T00:00:00Z' },
    user: { id: owner.id, name: owner.name, school: { id: school.id, name: school.name, slug: 'demo', status: 'active' },
      branches: [{ id: branch.id, name: branch.name, code: 'B1' }], roles: [{ role: 'owner', branch_id: null }] },
  };
  const add = (table: TableName, values: Record<string, any>) => insert(table, values, { local: true });
  const sessionRow = all('academic_sessions')[0];
  const year = Number(sessionRow.starts_on.slice(0, 4));
  const classes = [...all('classes')].sort((a, b) => a.sort_order - b.sort_order);
  const heads = Object.fromEntries(all('fee_heads').map((h) => [h.name, h]));
  const leaveTypes = all('leave_types');
  const shift = all('shifts')[0];

  for (const [date, name] of [[`${year}-11-09`, 'Iqbal Day'], [`${year}-12-25`, 'Quaid-e-Azam Day'], [`${year + 1}-02-05`, 'Kashmir Day'], [`${year}-08-14`, 'Independence Day']]) {
    add('holidays', { date, name, for_staff: true, for_students: true });
  }
  const holidays = new Set(all('holidays').map((h) => h.date));
  const schoolDay = (d: string) => weekday(d) !== 0 && !holidays.has(d);

  // ----- staff and logins -----
  const staff = STAFF.map(([name, designation, basic, allowances], i) => add('staff', {
    employee_no: `EMP-${String(i + 1).padStart(3, '0')}`, name, cnic: cnic(), phone: phone(), designation,
    joining_date: `${2018 + (i % 7)}-0${(i % 8) + 1}-01`, shift_id: shift.id, bank_name: i % 3 ? 'Meezan Bank' : 'HBL', bank_account: null,
    device_user_id: String(100 + i), status: 'active', basic_salary: basic, allowances }));
  const teachers = staff.filter((s) => s.designation === 'Teacher');
  const login = (name: string, phoneNo: string, role: string, staffId: string | null, familyId: string | null = null) => {
    const u = add('users', { name, phone: phoneNo, email: null, password: DEMO_PASSWORD, is_super_admin: false, status: 'active', staff_id: staffId, family_id: familyId });
    add('user_roles', { user_id: u.id, role, branch_id: role === 'teacher' ? branch.id : null });
    return u;
  };
  const principalUser = login('Saima Tariq', '03000000003', 'principal', staff[0].id);
  login('Bilal Ahmed', '03000000004', 'accountant', staff[1].id);
  login('Rabia Aslam', '03000000005', 'admin', staff[2].id);
  const examinerUser = login('Kamran Akmal', '03000000006', 'examiner', staff[3].id);
  const teacherUser = login('Hina Iqbal', '03000000002', 'teacher', staff[4].id);

  // ----- classes, sections, subjects -----
  // The school was provisioned with section A and the five core subjects for every class.
  const core = all('subjects');
  const subjects = [...core, ...SUBJECTS.slice(core.length).map((name) => add('subjects', { name, code: name.slice(0, 3).toUpperCase() }))];
  const bySection = new Map(all('sections').map((x) => [x.class_id, x]));
  const sections = classes.map((c, i) => update('sections', bySection.get(c.id)!.id, {
    // Hina Iqbal (the demo teacher) is class teacher of Class 5.
    class_teacher_id: c.name === '5' ? staff[4].id : teachers[(i + 1) % teachers.length].id }, { local: true })!);
  for (const sec of sections) {
    all('section_subjects').filter((x) => x.section_id === sec.id).forEach((row, i) => update('section_subjects', row.id,
      { teacher_id: i === 0 ? sec.class_teacher_id : teachers[Math.floor(r() * teachers.length)].id }, { local: true }));
  }

  // ----- fee structure -----
  classes.forEach((c, i) => {
    const tuition = i < 3 ? 3500 : i < 8 ? 4500 : i < 11 ? 5500 : 6500;
    const base = { academic_session_id: sessionRow.id, class_id: c.id, due_day: 10 };
    add('fee_structures', { ...base, fee_head_id: heads['Tuition fee'].id, amount: tuition, months: null });
    add('fee_structures', { ...base, fee_head_id: heads['Annual charges'].id, amount: 5000, months: [4] });
    add('fee_structures', { ...base, fee_head_id: heads['Exam fee'].id, amount: 1500, months: [9, 3] });
  });

  // ----- families and students -----
  const slots: { c: Row; ci: number }[] = [];
  classes.forEach((c, ci) => { for (let n = 0; n < 10; n++) slots.push({ c, ci }); });
  for (let i = slots.length - 1; i > 0; i--) { const j = Math.floor(r() * (i + 1)); [slots[i], slots[j]] = [slots[j], slots[i]]; }
  const rolls = new Map<string, number>();
  const ability = new Map<string, number>();
  let admission = 0, familyNo = 0;
  while (slots.length) {
    const size = Math.min(slots.length, r() < 0.55 ? 1 : r() < 0.7 ? 2 : 3);
    const kids: { c: Row; ci: number }[] = [];
    while (kids.length < size && slots.length) {
      const i = slots.findIndex((s) => !kids.some((k) => k.c.id === s.c.id));
      if (i < 0) break;
      kids.push(slots.splice(i, 1)[0]);
    }
    const father = pick(FATHERS), surname = pick(SURNAMES);
    const guardianPhone = familyNo === 0 ? '03000000007' : phone();
    const family = add('families', { code: `FAM-${String(++familyNo).padStart(3, '0')}`,
      primary_guardian_name: familyNo === 1 ? 'Imran Sheikh' : `${father} ${surname}`, primary_guardian_cnic: cnic(),
      primary_phone: guardianPhone, whatsapp_phone: guardianPhone, address: `House ${Math.floor(r() * 400) + 1}, ${pick(AREAS)}, Lahore` });
    add('guardians', { family_id: family.id, name: family.primary_guardian_name, relation: 'father', phone: guardianPhone, cnic: family.primary_guardian_cnic, occupation: pick(['Business', 'Government service', 'Engineer', 'Shopkeeper', 'Banker', 'Doctor']) });
    kids.sort((a, b) => b.ci - a.ci);
    for (const k of kids) {
      const girl = r() < 0.48;
      const student = add('students', { family_id: family.id, admission_no: `ADM-${String(++admission).padStart(4, '0')}`,
        name: `${pick(girl ? GIRLS : BOYS)} ${family.primary_guardian_name.split(' ')[0]}`, b_form: cnic(),
        date_of_birth: `${year - 4 - k.ci}-${String(Math.floor(r() * 12) + 1).padStart(2, '0')}-${String(Math.floor(r() * 27) + 1).padStart(2, '0')}`,
        gender: girl ? 'female' : 'male', photo_path: null, admission_date: `${year - Math.floor(r() * Math.min(k.ci + 1, 4))}-04-${String(Math.floor(r() * 9) + 1).padStart(2, '0')}`,
        status: 'active', left_on: null, notes: null });
      const section = sections[k.ci];
      const roll = (rolls.get(section.id) ?? 0) + 1;
      rolls.set(section.id, roll);
      add('enrollments', { student_id: student.id, academic_session_id: sessionRow.id, section_id: section.id, roll_no: String(roll), status: 'active' });
      ability.set(student.id, 0.45 + r() * 0.5);
    }
  }
  const families = all('families');
  const students = all('students');
  login('Imran Sheikh', '03000000007', 'parent', null, families[0].id);
  for (const s of [students[7], students[23]]) {
    add('student_discounts', { student_id: s.id, discount_rule_id: all('discount_rules')[0].id, percent: 25, fixed_amount: null,
      valid_from: sessionRow.starts_on, valid_to: null, approved_by: principalUser.id, reason: 'Merit scholarship' });
  }

  // ----- student attendance: last 30 days -----
  const enrollments = all('enrollments');
  const weak = new Set(students.filter(() => r() < 0.06).map((s) => s.id));
  for (let back = 30; back >= 0; back--) {
    const date = addDays(today, -back);
    if (!schoolDay(date)) continue;
    for (const e of enrollments) {
      if (back === 0 && sections.findIndex((s) => s.id === e.section_id) % 3 === 2) continue; // some classes not marked yet today
      const x = r();
      const absentAt = weak.has(e.student_id) ? 0.25 : 0.04;
      const status = x < absentAt ? 'absent' : x < absentAt + 0.03 ? 'leave' : x < absentAt + 0.05 ? 'late' : 'present';
      add('student_attendance', { student_id: e.student_id, section_id: e.section_id, date, status, remark: null });
    }
  }

  // ----- fees: four months of vouchers and payments -----
  const now = period(new Date(today + 'T12:00:00'));
  let receipt = 0;
  const methods = ['cash', 'cash', 'cash', 'bank', 'jazzcash', 'easypaisa'];
  [3, 2, 1, 0].forEach((back) => {
    const p = addMonths(now, -back);
    voucherRunLocal(p, `${p}-01`);
    const lastDay = back === 0 ? Math.max(1, Number(today.slice(8, 10))) : daysInMonth(p);
    const payRate = back === 0 ? 0.55 : back === 1 ? 0.86 : 0.94;
    for (const f of families) {
      const invoice = all('invoices').find((i) => i.family_id === f.id && i.period === p);
      if (!invoice) continue;
      const invoiced = all('invoices').filter((i) => i.family_id === f.id).reduce((s, i) => s + all('invoice_lines').filter((l) => l.invoice_id === i.id).reduce((t, l) => t + l.amount, 0), 0);
      const paid = all('payments').filter((x) => x.family_id === f.id).reduce((s, x) => s + x.amount, 0);
      const balance = invoiced - paid;
      const x = r();
      if (balance <= 0 || x > payRate || (f.id === families[0].id && back === 0)) continue; // the demo parent still owes this month
      const amount = x > payRate - 0.05 ? Math.max(1000, Math.round(balance / 2000) * 1000) : balance;
      const day = Math.min(lastDay, 2 + Math.floor(r() * 17));
      add('payments', { family_id: f.id, receipt_no: `B1-D01-${String(++receipt).padStart(6, '0')}`, amount: Math.min(amount, balance), method: pick(methods),
        reference: null, received_at: new Date(`${p}-${String(day).padStart(2, '0')}T${String(8 + Math.floor(r() * 5)).padStart(2, '0')}:${String(Math.floor(r() * 60)).padStart(2, '0')}:00`).toISOString(),
        received_by: owner.id, sync_state: 'accepted' });
    }
  });

  // ----- staff attendance, leave, payroll -----
  const prev = addMonths(now, -1);
  const stamp = (date: string, hhmm: string) => new Date(`${date}T${hhmm}:00`).toISOString();
  for (const p of [prev, now]) {
    for (let d = 1; d <= daysInMonth(p); d++) {
      const date = `${p}-${String(d).padStart(2, '0')}`;
      if (date > today) break;
      for (const s of staff) {
        const base = { staff_id: s.id, shift_date: date, check_in: null as string | null, check_out: null as string | null, late_minutes: 0,
          early_leave_minutes: 0, overtime_minutes: 0, punch_count: 0, locked: false };
        if (weekday(date) === 0) { add('staff_attendance_days', { ...base, status: 'weekly_off' }); continue; }
        if (holidays.has(date)) { add('staff_attendance_days', { ...base, status: 'holiday' }); continue; }
        const x = r();
        const status = x < 0.025 ? 'absent' : x < 0.045 ? 'leave_paid' : x < 0.055 ? 'half_day' : x < 0.12 ? 'late' : 'present';
        const late = status === 'late' ? 12 + Math.floor(r() * 30) : 0;
        const worked = ['present', 'late', 'half_day'].includes(status);
        add('staff_attendance_days', { ...base, status, late_minutes: late, punch_count: worked ? 2 : 0,
          check_in: worked ? stamp(date, `07:${String(Math.min(59, 35 + Math.floor(r() * 10) + late)).padStart(2, '0')}`) : null,
          check_out: worked && date !== today ? stamp(date, status === 'half_day' ? '11:00' : `14:${String(Math.floor(r() * 20)).padStart(2, '0')}`) : null });
      }
    }
  }
  add('staff_leaves', { staff_id: staff[6].id, leave_type_id: leaveTypes[0].id, from_date: addDays(today, 2), to_date: addDays(today, 3), half_day: false, reason: 'Family wedding in Multan', status: 'requested', decided_by: null, decided_at: null });
  add('staff_leaves', { staff_id: staff[9].id, leave_type_id: leaveTypes[1].id, from_date: addDays(today, 1), to_date: addDays(today, 1), half_day: false, reason: 'Fever', status: 'requested', decided_by: null, decided_at: null });
  add('staff_leaves', { staff_id: staff[5].id, leave_type_id: leaveTypes[0].id, from_date: addDays(today, -12), to_date: addDays(today, -12), half_day: false, reason: 'Personal work', status: 'approved', decided_by: principalUser.id, decided_at: stamp(addDays(today, -14), '10:00') });
  add('staff_loans', { staff_id: staff[17].id, amount: 30000, installment: 5000, starts_period: prev, status: 'active', approved_by: owner.id });
  const run = payrollDraftLocal(prev, branch.id);
  payrollApproveLocal(run.run_id, owner.id);
  payrollPayLocal(run.run_id);

  // ----- exams and marks -----
  const term = add('exams', { academic_session_id: sessionRow.id, name: 'First Term', starts_on: `${year}-09-15`, ends_on: `${year}-09-26`, status: 'published' });
  const mid = add('exams', { academic_session_id: sessionRow.id, name: 'Mid Term', starts_on: addDays(today, -6), ends_on: addDays(today, 4), status: 'marks_entry' });
  for (const exam of [term, mid]) {
    classes.forEach((c, ci) => core.forEach((sub, si) => {
      const es = add('exam_subjects', { exam_id: exam.id, class_id: c.id, subject_id: sub.id, max_marks: ci < 3 ? 50 : 100, pass_marks: ci < 3 ? 17 : 33,
        paper_date: addDays(exam.starts_on, si * 2) });
      if (exam === mid && (si > 1 || ci % 2 === 1)) return; // marks entry still in progress
      for (const e of enrollments.filter((x) => x.section_id === sections[ci].id)) {
        const score = Math.max(0.12, Math.min(0.99, (ability.get(e.student_id) ?? 0.6) + (r() - 0.5) * 0.3));
        const gone = r() < 0.015;
        add('marks', { exam_subject_id: es.id, student_id: e.student_id, obtained: gone ? null : Math.round(score * es.max_marks), absent: gone });
      }
    }));
  }

  // ----- paperless exam papers -----
  const paper = (ci: number, si: number, status: string, note: string | null = null) => add('exam_papers', {
    exam_id: mid.id, class_id: classes[ci].id, subject_id: core[si].id, title: `Mid Term – ${core[si].name} – ${/^\d+$/.test(classes[ci].name) ? 'Class ' : ''}${classes[ci].name}`,
    total_marks: 100, duration_minutes: 120, status, author_id: teacherUser.id, review_note: note,
    content: `Section A – Objective (20 marks)\nQ1. Choose the correct answer. (10 x 1)\nQ2. Fill in the blanks. (10 x 1)\n\nSection B – Short questions (40 marks)\nQ3. Answer any eight of the following. (8 x 5)\n\nSection C – Long questions (40 marks)\nQ4. Attempt any two questions. (2 x 20)` });
  paper(7, 0, 'draft'); paper(7, 2, 'submitted'); paper(8, 3, 'reviewed', 'Checked. Balanced paper, ready for final approval.');
  paper(6, 1, 'approved', 'Approved for printing.'); paper(9, 2, 'returned', 'Q4 is outside the syllabus covered so far. Please replace it.');

  // ----- syllabus, homework, notices -----
  const topics: Record<string, string[]> = {
    English: ['Nouns and pronouns', 'Tenses: present and past', 'Reading comprehension', 'Paragraph writing', 'Letter writing', 'Active and passive voice'],
    Mathematics: ['Whole numbers', 'Fractions', 'Decimals', 'Percentages', 'Basic geometry', 'Data handling'],
    Science: ['Living things', 'Human body systems', 'Matter and its states', 'Force and motion', 'Light and sound', 'Earth and space'],
  };
  for (let ci = 5; ci <= 10; ci++) {
    for (const sub of subjects.filter((s) => topics[s.name])) {
      const done = 2 + Math.floor(r() * 3);
      topics[sub.name].forEach((title, i) => add('syllabus_topics', { class_id: classes[ci].id, subject_id: sub.id, title, sort_order: i + 1,
        status: i < done ? 'done' : i === done ? 'in_progress' : 'pending', completed_on: i < done ? addDays(today, -60 + i * 14) : null }));
    }
  }
  const work: [number, number, string, string, string][] = [
    [7, 2, 'homework', 'Exercise 4.2, questions 1 to 10', 'Solve in the homework copy. Show all working.'],
    [7, 0, 'worksheet', 'Tenses worksheet', 'Fill in the blanks with the correct form of the verb. 20 questions.'],
    [7, 3, 'diary', 'Science quiz on Friday', 'Chapter 5: Force and motion. Revise the definitions.'],
    [4, 1, 'homework', 'Urdu mazmoon: Mera School', 'Das jumlay likhain.'],
    [9, 2, 'worksheet', 'Algebra practice sheet', 'Simplify the expressions. Bring the sheet on Monday.'],
    [2, 0, 'diary', 'Bring colour pencils', 'Art activity tomorrow.'],
  ];
  work.forEach(([ci, si, kind, title, body], i) => add('homework', { section_id: sections[ci].id, subject_id: core[si].id, kind, title, body,
    assigned_on: addDays(today, -(i % 3)), due_on: kind === 'diary' ? null : addDays(today, 2 - (i % 3)) }));
  add('notices', { title: 'Mid Term examinations', body: 'Mid Term examinations are in progress. Date sheets have been shared with all classes. Students must bring their own stationery.', audience: 'all', published_on: addDays(today, -8) });
  add('notices', { title: 'Parent teacher meeting', body: 'A parent teacher meeting will be held on Saturday from 9:00 am to 12:00 pm. Results will be discussed.', audience: 'parents', published_on: addDays(today, -2) });
  add('notices', { title: 'Winter uniform', body: 'Winter uniform is compulsory from 1 December.', audience: 'all', published_on: addDays(today, -1) });

  // ----- message history and expenses -----
  families.slice(3, 9).forEach((f, i) => add('messages', { family_id: f.id, to_name: f.primary_guardian_name, to_phone: f.whatsapp_phone, channel: i % 4 === 3 ? 'sms' : 'whatsapp',
    kind: i % 2 ? 'absent' : 'late_fee', status: 'sent', sent_at: stamp(addDays(today, -1 - i), '09:15'), error: null,
    body: i % 2 ? `Assalam-o-Alaikum ${f.primary_guardian_name}, your child was absent from school today. Please inform the class teacher. – ${school.name}`
      : `Assalam-o-Alaikum ${f.primary_guardian_name}, school fee is pending. Please pay at the earliest to avoid late fee. – ${school.name}` }));
  const spend: [string, string, number, string][] = [['Utilities', 'Electricity bill', 48500, 'LESCO'], ['Utilities', 'Internet', 6500, 'PTCL'], ['Stationery', 'Markers, registers, chalk', 12800, 'Urdu Bazaar'],
    ['Maintenance', 'Classroom fans repair', 9500, 'Electrician'], ['Events', 'Iqbal Day function', 15000, ''], ['Printing', 'Exam papers printing', 22000, 'Al-Hamd Printers'],
    ['Transport', 'Van fuel', 31000, 'PSO'], ['Cleaning', 'Cleaning supplies', 5400, '']];
  spend.forEach(([category, description, amount, paid_to], i) => add('expenses', { category, description, amount, paid_to: paid_to || null, spent_on: addDays(today, -(i * 3 + 1)) }));

  // Exam fee note for the examiner login so the role has something to open.
  void examinerUser;
  session.data = null;
}

/** Row counts, used by tests and the export script. */
export const demoSummary = () => Object.fromEntries((['schools', 'students', 'families', 'staff', 'invoices', 'payments', 'student_attendance', 'marks', 'payslips'] as TableName[]).map((t) => [t, raw(t).length]));
