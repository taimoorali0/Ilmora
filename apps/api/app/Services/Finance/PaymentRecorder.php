<?php

namespace App\Services\Finance;

use App\Domain\Finance\PaymentAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * After a payment row is accepted: allocate it to open invoice lines,
 * post the ledger journal, and flag overpayment for reconciliation (D3).
 */
final class PaymentRecorder
{
    public function __construct(private readonly PaymentAllocator $allocator) {}

    public function afterInsert(string $paymentId): void
    {
        $p = DB::table('payments')->where('id', $paymentId)->first();

        $open = $this->openLines($p->family_id);
        $result = $this->allocator->allocate((int) $p->amount, $open);

        foreach ($result['allocations'] as $a) {
            DB::table('payment_allocations')->insert([
                'id' => (string) Str::uuid(), 'school_id' => $p->school_id, 'branch_id' => $p->branch_id,
                'payment_id' => $p->id, 'invoice_id' => $a['invoice_id'], 'invoice_line_id' => null,
                'amount' => $a['amount'], 'created_by' => $p->created_by, 'device_id' => $p->device_id,
                'created_at' => now(),
            ]);
        }
        $this->refreshInvoiceStatus(array_unique(array_column($result['allocations'], 'invoice_id')));

        // Journal: Dr Cash/Bank, Cr Family receivable.
        $journal = (string) Str::uuid();
        $cash = $this->account($p->school_id, $p->method === 'cash' ? '1100' : '1200');
        $receivable = $this->familyAccount($p->school_id, $p->family_id);
        foreach ([[$cash, $p->amount, 0], [$receivable, 0, $p->amount]] as [$acc, $dr, $cr]) {
            DB::table('ledger_entries')->insert([
                'id' => (string) Str::uuid(), 'school_id' => $p->school_id, 'branch_id' => $p->branch_id,
                'journal_id' => $journal, 'account_id' => $acc, 'debit' => $dr, 'credit' => $cr,
                'entry_date' => substr((string) $p->received_at, 0, 10), 'source_type' => 'payment',
                'source_id' => $p->id, 'memo' => "Receipt {$p->receipt_no}", 'created_by' => $p->created_by,
                'device_id' => $p->device_id, 'created_at' => now(),
            ]);
        }

        if ($result['credit'] > 0) {
            DB::table('payments')->where('id', $p->id)->update(['sync_state' => 'pending_reconciliation']);
            DB::table('reconciliation_items')->insert([
                'id' => (string) Str::uuid(), 'school_id' => $p->school_id, 'branch_id' => $p->branch_id,
                'kind' => 'overpayment', 'family_id' => $p->family_id,
                'refs' => json_encode(['payment_id' => $p->id]), 'amount' => $result['credit'],
                'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Net outstanding per invoice (fees + fines - discounts - live allocations).
     * Allocation is per invoice, oldest period first, because discounts are
     * negative lines that reduce the invoice total rather than a single fee line.
     */
    private function openLines(string $familyId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT i.id AS invoice_id, i.period,
                   COALESCE((SELECT SUM(l.amount) FROM invoice_lines l
                             WHERE l.invoice_id = i.id
                               AND NOT EXISTS (SELECT 1 FROM reversals r WHERE r.target_type = 'invoice_line' AND r.target_id = l.id)), 0)
                 - COALESCE((SELECT SUM(a.amount) FROM payment_allocations a
                             WHERE a.invoice_id = i.id
                               AND NOT EXISTS (SELECT 1 FROM reversals r WHERE r.target_type = 'payment_allocation' AND r.target_id = a.id)
                               AND NOT EXISTS (SELECT 1 FROM reversals r WHERE r.target_type = 'payment' AND r.target_id = a.payment_id)), 0)
                   AS outstanding
            FROM invoices i
            WHERE i.family_id = ? AND i.status <> 'cancelled'
            SQL, [$familyId]);

        return array_map(fn ($r) => [
            'line_id' => $r->invoice_id, 'invoice_id' => $r->invoice_id, 'period' => $r->period,
            'kind' => 'invoice', 'outstanding' => (int) $r->outstanding,
        ], $rows);
    }

    private function refreshInvoiceStatus(array $invoiceIds): void
    {
        foreach ($invoiceIds as $id) {
            $due = (int) DB::table('invoice_lines')->where('invoice_id', $id)->sum('amount');
            $paid = (int) DB::table('payment_allocations')->where('invoice_id', $id)->sum('amount');
            DB::table('invoices')->where('id', $id)->update([
                'status' => $paid >= $due ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued'),
                'version' => DB::raw('version + 1'), 'updated_at' => now(),
            ]);
        }
    }

    private function account(string $schoolId, string $code): string
    {
        return DB::table('accounts')->where('school_id', $schoolId)->where('code', $code)->value('id')
            ?? throw new \RuntimeException("Chart of accounts missing $code for school.");
    }

    private function familyAccount(string $schoolId, string $familyId): string
    {
        $id = DB::table('accounts')->where('school_id', $schoolId)->where('owner_type', 'family')
            ->where('owner_id', $familyId)->value('id');
        if ($id) {
            return $id;
        }
        $id = (string) Str::uuid();
        DB::table('accounts')->insert([
            'id' => $id, 'school_id' => $schoolId, 'code' => '1300-'.substr($familyId, 0, 8),
            'name' => 'Receivable – family', 'type' => 'asset', 'owner_type' => 'family', 'owner_id' => $familyId,
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
