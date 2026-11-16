/**
 * Device-side rules, run on the demo data set:  npm test
 */
import assert from 'node:assert/strict';
import { before, test } from 'node:test';
import { DEMO_LOGINS, DEMO_PASSWORD, seedDemo } from '../src/lib/demo';
import { classPositions, examResult } from '../src/lib/logic/exams';
import { collectPayment, familyLedger, invoiceTotal, reversePayment, voucherRunLocal } from '../src/lib/logic/fees';
import { calculate, emptySummary, payrollDraftLocal } from '../src/lib/logic/payroll';
import { provisionSchool } from '../src/lib/provision';
import { admitStudent, newFamily } from '../src/lib/records';
import { sectionStudents, sortedSections } from '../src/lib/school';
import { session } from '../src/lib/session';
import { all, raw } from '../src/lib/store';

const TODAY = '2026-11-16';

function signIn(phone: string) {
  const user = raw('users').find((u) => u.phone === phone)!;
  const school = raw('schools').find((s) => s.id === user.school_id)!;
  session.data = {
    token: 't', access: 'full', device: { id: 'd', code: 'D07', offline_valid_until: '2099-01-01T00:00:00Z' },
    user: { id: user.id, name: user.name, staff_id: user.staff_id, family_id: user.family_id, school: { id: school.id, name: school.name, slug: school.slug, status: 'active' },
      branches: raw('branches').filter((b) => b.school_id === school.id).map((b) => ({ id: b.id, name: b.name, code: b.code })),
      roles: raw('user_roles').filter((r) => r.user_id === user.id).map((r) => ({ role: r.role, branch_id: r.branch_id })) },
  };
}

before(() => { seedDemo(TODAY); signIn('03000000001'); });

test('demo data has every login and a full school', () => {
  for (const d of DEMO_LOGINS) assert.equal(raw('users').find((u) => u.phone === d.login)?.password, DEMO_PASSWORD, d.role);
  assert.equal(all('students').length, 130);
  assert.equal(sortedSections().length, 13);
  assert.equal(all('staff').length, 18);
  assert.ok(raw('users').some((u) => u.is_super_admin));
});

test('a school only sees its own rows', () => {
  const mine = session.data!.user.school!.id;
  for (const t of ['students', 'families', 'invoices', 'payments', 'staff', 'sections'] as const) assert.ok(all(t).every((r) => r.school_id === mine), t);
  assert.ok(raw('sections').length > all('sections').length); // the second school has its own sections
});

test('family ledger: invoiced minus paid, oldest voucher settled first', () => {
  for (const f of all('families')) {
    const l = familyLedger(f.id);
    assert.equal(l.balance, l.invoiced - l.paid);
    assert.equal(l.invoices.reduce((s, i) => s + i.total, 0), l.invoiced);
    let seenUnpaid = false;
    for (const i of l.invoices) {
      if (seenUnpaid) assert.equal(i.paid, 0, `${f.code}: a later voucher is paid before an earlier one`);
      if (i.due > 0) seenUnpaid = true;
    }
  }
});

test('sibling discount: second child 10%, third 15%, on tuition only', () => {
  const scholars = new Set(all('student_discounts').map((d) => d.student_id));
  const family = all('families').find((f) => {
    const kids = all('students').filter((s) => s.family_id === f.id);
    return kids.length === 3 && !kids.some((k) => scholars.has(k.id));
  })!;
  const invoice = all('invoices').find((i) => i.family_id === family.id && i.period === '2026-10')!;
  const lines = all('invoice_lines').filter((l) => l.invoice_id === invoice.id);
  const discounts = lines.filter((l) => l.kind === 'discount' && l.description.startsWith('Discount'));
  assert.deepEqual(discounts.map((l) => l.description.match(/(\d+)%/)![1]).sort(), ['10', '15']);
  for (const d of discounts) {
    const fee = lines.find((l) => l.kind === 'fee' && l.student_id === d.student_id && l.fee_head_id === d.fee_head_id)!;
    assert.equal(-d.amount, Math.floor((fee.amount * Number(d.description.match(/(\d+)%/)![1])) / 100));
  }
  assert.equal(invoiceTotal(invoice.id), lines.reduce((s, l) => s + l.amount, 0));
});

test('voucher run is repeatable and adds a late fee to overdue families', () => {
  const again = voucherRunLocal('2026-11', TODAY);
  assert.equal(again.created, 0);
  const next = voucherRunLocal('2026-12', '2026-12-01');
  assert.ok(next.created > 80);
  const owing = all('families').find((f) => familyLedger(f.id).invoices.some((i) => i.invoice.period === '2026-11' && i.due > 0))!;
  const dec = all('invoices').find((i) => i.family_id === owing.id && i.period === '2026-12')!;
  assert.ok(all('invoice_lines').some((l) => l.invoice_id === dec.id && l.kind === 'fine' && l.amount === 200));
});

test('payment: device receipt number, never reused, reversal restores the balance', () => {
  const family = all('families').find((f) => familyLedger(f.id).balance > 1000)!;
  const before = familyLedger(family.id).balance;
  const p = collectPayment(family.id, 1000, 'cash');
  assert.match(p.receipt_no, /^B1-D07-\d{6}$/);
  assert.equal(familyLedger(family.id).balance, before - 1000);
  const q = collectPayment(family.id, 500, 'bank');
  assert.notEqual(q.receipt_no, p.receipt_no);
  reversePayment(q.id, 'mistake');
  assert.equal(familyLedger(family.id).balance, before - 1000);
  assert.throws(() => collectPayment(family.id, 0, 'cash'));
  assert.throws(() => collectPayment(family.id, 10.5, 'cash'));
});

test('admission: codes carry the device code and the roll number follows the class', () => {
  const family = newFamily({ primary_guardian_name: 'Test Father', primary_phone: '03219876543', whatsapp_phone: '03219876543' });
  assert.match(family.code, /^FAM-D07-\d{4}$/);
  const section = sortedSections()[4];
  const size = sectionStudents(section.id).length;
  const s = admitStudent(family.id, { name: 'Test Child', gender: 'female', admission_date: TODAY }, section.id);
  assert.match(s.admission_no, /^ADM-D07-\d{4}$/);
  assert.equal(sectionStudents(section.id).length, size + 1);
  assert.equal(sectionStudents(section.id).at(-1)!.id, s.id);
});

test('payroll: absences and lates are deducted, incomplete punches block the slip', () => {
  const att = { ...emptySummary(), present: 20, late: 6, absent: 1, half_day: 1 };
  const slip = calculate([{ name: 'Basic salary', type: 'earning', amount: 52000 }], att, { day_basis: 'working_days', lates_per_day: 3 }, 5000, 26);
  // 1 absent + 2 late-days + half a day = 3.5 days of 26
  assert.equal(slip.total_deductions, Math.floor((52000 * 7) / 52) + 5000);
  assert.equal(slip.net, slip.gross - slip.total_deductions);
  assert.throws(() => calculate([], { ...emptySummary(), incomplete: 1 }, { day_basis: 'working_days', lates_per_day: 3 }, 0, 26));
  const draft = payrollDraftLocal('2026-11', session.data!.user.branches[0].id);
  assert.equal(draft.payslips, 18);
  assert.throws(() => payrollDraftLocal('2026-10', session.data!.user.branches[0].id)); // October is already approved and paid
});

test('exam result: totals, pass rule and class positions', () => {
  const exam = all('exams').find((e) => e.status === 'published')!;
  const section = sortedSections()[7];
  const positions = classPositions(exam.id, section.id);
  const ranked = sectionStudents(section.id).map((s) => ({ pos: positions.get(s.id)!, r: examResult(exam.id, s.id) })).sort((a, b) => a.pos - b.pos);
  assert.equal(ranked[0].pos, 1);
  for (let i = 1; i < ranked.length; i++) assert.ok(ranked[i - 1].r.obtained >= ranked[i].r.obtained);
  for (const { r } of ranked) {
    assert.equal(r.obtained, r.lines.reduce((s, l) => s + (l.obtained ?? 0), 0));
    assert.equal(r.passed, r.lines.every((l) => l.obtained !== null && l.obtained >= l.pass));
  }
});

test('super admin provisioning: unique code, usable starter setup', () => {
  const plan = raw('plans')[0];
  const { school, owner } = provisionSchool({ name: 'New School', slug: 'New School!', owner_name: 'Owner', owner_phone: '03111222333', owner_password: 'secret12', plan_id: plan.id }, TODAY);
  assert.equal(school.slug, 'new-school');
  assert.equal(raw('sections').filter((s) => s.school_id === school.id).length, 13);
  assert.equal(raw('section_subjects').filter((s) => s.school_id === school.id).length, 65);
  assert.equal(raw('user_roles').find((r) => r.user_id === owner.id)?.role, 'owner');
  assert.throws(() => provisionSchool({ name: 'Copy', slug: 'new-school', owner_name: 'O', owner_phone: '0', owner_password: 'x', plan_id: plan.id }, TODAY), /already taken/);
});
