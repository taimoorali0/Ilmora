import { computed } from 'vue';
import { addDays, today as todayYmd } from '../format';
import { currentSession, enrollmentOf } from '../school';
import { branchCode, session } from '../session';
import { all, find, groupBy, insert, nextCounter, type Row } from '../store';

/**
 * Fees on the device (docs/decisions.md D3, D4).
 * A family's balance is what was invoiced minus what was received. Payments settle
 * the oldest invoice first, so the status of every invoice can be worked out
 * offline from invoices and payments alone. The server keeps the formal ledger.
 */
export interface InvoiceState { invoice: Row; total: number; paid: number; due: number; status: 'paid' | 'partial' | 'unpaid'; overdue: boolean }
export interface FamilyLedger { invoiced: number; paid: number; balance: number; overdue: number; invoices: InvoiceState[] }

const reversedIds = computed(() => new Set(all('reversals').map((r) => r.target_id)));

export const invoiceTotal = (invoiceId: string) =>
  (groupBy('invoice_lines', 'invoice_id').get(invoiceId) ?? []).reduce((s, l) => s + l.amount, 0);

export const livePayments = (familyId?: string): Row[] =>
  (familyId ? groupBy('payments', 'family_id').get(familyId) ?? [] : all('payments')).filter((p) => !reversedIds.value.has(p.id));

const ledgers = computed(() => {
  const map = new Map<string, FamilyLedger>();
  const now = todayYmd();
  const paidByFamily = new Map<string, number>();
  for (const p of livePayments()) paidByFamily.set(p.family_id, (paidByFamily.get(p.family_id) ?? 0) + p.amount);
  const families = new Set<string>([...groupBy('invoices', 'family_id').keys(), ...paidByFamily.keys()]);
  for (const familyId of families) {
    const invoices = (groupBy('invoices', 'family_id').get(familyId) ?? [])
      .filter((i) => i.status !== 'cancelled' && !reversedIds.value.has(i.id))
      .sort((a, b) => a.period.localeCompare(b.period));
    const paid = paidByFamily.get(familyId) ?? 0;
    let left = paid;
    let invoiced = 0;
    let overdue = 0;
    const states = invoices.map((invoice): InvoiceState => {
      const total = invoiceTotal(invoice.id);
      const take = Math.max(0, Math.min(left, total));
      left -= take;
      invoiced += total;
      const due = total - take;
      const late = due > 0 && invoice.due_date < now;
      if (late) overdue += due;
      return { invoice, total, paid: take, due, status: due <= 0 ? 'paid' : take > 0 ? 'partial' : 'unpaid', overdue: late };
    });
    map.set(familyId, { invoiced, paid, balance: invoiced - paid, overdue, invoices: states });
  }
  return map;
});

const EMPTY: FamilyLedger = { invoiced: 0, paid: 0, balance: 0, overdue: 0, invoices: [] };
export const familyLedger = (familyId: string): FamilyLedger => ledgers.value.get(familyId) ?? EMPTY;
export const allLedgers = () => ledgers.value;

/** Families that owe money, largest first. */
export function defaulters(): { family: Row; ledger: FamilyLedger }[] {
  return [...ledgers.value.entries()]
    .filter(([, l]) => l.balance > 0)
    .map(([id, ledger]) => ({ family: find('families', id) as Row, ledger }))
    .filter((d) => d.family)
    .sort((a, b) => b.ledger.balance - a.ledger.balance);
}

/** Record money received. Works offline; the receipt number is unique to this device (D4). */
export function collectPayment(familyId: string, amount: number, method: string, reference = ''): Row {
  if (!Number.isInteger(amount) || amount <= 0) throw new Error('Enter the amount in whole rupees.');
  const device = session.data?.device.code ?? 'D00';
  // Never reuse a number this device already issued (the counter restarts after a device is wiped).
  const prefix = `${branchCode()}-${device}-`;
  const used = all('payments').reduce((m, p) => (String(p.receipt_no).startsWith(prefix) ? Math.max(m, Number(String(p.receipt_no).slice(prefix.length)) || 0) : m), 0);
  let n = nextCounter('receipt');
  while (n <= used) n = nextCounter('receipt');
  const receiptNo = `${prefix}${String(n).padStart(6, '0')}`;
  return insert('payments', {
    family_id: familyId, receipt_no: receiptNo, amount, method, reference: reference || null,
    received_at: new Date().toISOString(), received_by: session.data?.user.id ?? null, sync_state: 'accepted',
  });
}

export function reversePayment(paymentId: string, reason: string): Row {
  return insert('reversals', { target_type: 'payment', target_id: paymentId, reason });
}

/**
 * Monthly voucher run on this device. Used by the demo; with a server the same
 * rules run in App\Services\Finance\VoucherRun and the result is pulled.
 */
export function voucherRunLocal(periodYm: string, issueDate = todayYmd()): { created: number; skipped: number; total: number } {
  const sessionRow = currentSession();
  if (!sessionRow) throw new Error('No current academic session.');
  const month = Number(periodYm.slice(5, 7));
  const heads = new Map(all('fee_heads').map((h) => [h.id, h]));
  const structures = groupBy('fee_structures', 'class_id');
  const sibling = all('discount_rules').find((r) => r.kind === 'sibling' && r.active !== false);
  const siblingMap: Record<string, number> = typeof sibling?.config === 'string' ? JSON.parse(sibling.config) : sibling?.config ?? {};
  const maxPercent = sibling?.max_total_percent ?? 50;
  const fine = all('fine_rules').find((r) => r.active !== false);
  const done = new Set(all('invoices').filter((i) => i.period === periodYm).map((i) => i.family_id));
  let seq = done.size;
  // Families with older unpaid invoices past the grace period get the late fee line.
  const cutoff = fine ? addDays(issueDate, -(fine.grace_days ?? 0)) : issueDate;
  const overdue = new Set([...ledgers.value.entries()]
    .filter(([, l]) => l.invoices.some((s) => s.due > 0 && s.invoice.due_date < cutoff)).map(([id]) => id));
  let created = 0, skipped = 0, total = 0;

  const byFamily = new Map<string, Row[]>();
  for (const s of all('students')) {
    if (s.status !== 'active') continue;
    const e = enrollmentOf(s.id);
    if (!e || e.status !== 'active' || e.academic_session_id !== sessionRow.id) continue;
    const list = byFamily.get(s.family_id) ?? [];
    list.push({ ...s, class_id: find('sections', e.section_id)?.class_id });
    byFamily.set(s.family_id, list);
  }

  for (const [familyId, kids] of byFamily) {
    if (done.has(familyId)) { skipped++; continue; }
    kids.sort((a, b) => String(a.admission_date).localeCompare(String(b.admission_date)) || String(a.admission_no).localeCompare(String(b.admission_no)));
    const lines: Record<string, any>[] = [];
    let dueDay = 10;
    kids.forEach((child, i) => {
      const n = i + 1;
      const own = (groupBy('student_discounts', 'student_id').get(child.id) ?? [])
        .filter((d) => d.valid_from <= issueDate && (!d.valid_to || d.valid_to >= issueDate));
      const pct = Math.min(maxPercent, (siblingMap[String(n)] ?? (n >= 4 ? siblingMap['4+'] ?? 0 : 0)) + own.reduce((s, d) => s + (d.percent ?? 0), 0));
      const fixed = own.reduce((s, d) => s + (d.fixed_amount ?? 0), 0);
      for (const fs of structures.get(child.class_id) ?? []) {
        const head = heads.get(fs.fee_head_id);
        if (!head || head.active === false || fs.academic_session_id !== sessionRow.id) continue;
        const months: number[] | null = typeof fs.months === 'string' ? JSON.parse(fs.months) : fs.months ?? null;
        const applies = head.frequency === 'monthly' ? !months || months.includes(month)
          : ['yearly', 'per_term'].includes(head.frequency) ? !!months && months.includes(month) : false;
        if (!applies) continue;
        dueDay = fs.due_day ?? 10;
        lines.push({ student_id: child.id, fee_head_id: head.id, kind: 'fee', description: `${head.name} – ${child.name}`, amount: fs.amount, discount_rule_id: null });
        if (head.discountable !== false && pct > 0) {
          lines.push({ student_id: child.id, fee_head_id: head.id, kind: 'discount', description: `Discount ${pct}% – ${child.name}`,
            amount: -Math.floor((fs.amount * pct) / 100), discount_rule_id: sibling?.id ?? null });
        }
      }
      if (fixed > 0) lines.push({ student_id: child.id, fee_head_id: null, kind: 'discount', description: `Scholarship – ${child.name}`, amount: -fixed, discount_rule_id: null });
    });
    if (!lines.length) continue;
    if (fine && overdue.has(familyId)) {
      lines.push({ student_id: null, fee_head_id: null, kind: 'fine', description: 'Late fee on previous dues', amount: fine.amount, discount_rule_id: null });
    }
    const invoice = insert('invoices', {
      family_id: familyId, number: `INV-${branchCode()}-${periodYm}-${String(++seq).padStart(5, '0')}`, period: periodYm,
      issue_date: issueDate, due_date: `${periodYm}-${String(Math.min(28, dueDay)).padStart(2, '0')}`, status: 'issued', challan_ref: null,
    }, { local: true });
    for (const l of lines) insert('invoice_lines', { ...l, invoice_id: invoice.id }, { local: true });
    created++;
    total += lines.reduce((s, l) => s + l.amount, 0);
  }
  return { created, skipped, total };
}
