<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monthly fee voucher run (Finalize level, docs/decisions.md D1).
 *
 * One invoice per family per period. Lines per child from the class fee structure,
 * then sibling and student discounts, then a late fine if older invoices are overdue.
 * Safe to run twice: families that already have an invoice for the period are skipped.
 */
final class VoucherRun
{
    /** @return array{created:int, skipped:int, total:int} */
    public function run(string $schoolId, string $branchId, string $period, ?string $userId = null): array
    {
        $month = (int) substr($period, 5, 2);
        $session = DB::table('academic_sessions')->where('school_id', $schoolId)->where('is_current', true)->first();
        if (! $session) {
            throw new \DomainException('No current academic session.');
        }
        $branchCode = DB::table('branches')->where('id', $branchId)->where('school_id', $schoolId)->value('code')
            ?? throw new \DomainException('Unknown branch.');

        $students = DB::table('students as s')
            ->join('enrollments as e', fn ($j) => $j->on('e.student_id', '=', 's.id')
                ->where('e.academic_session_id', $session->id)->where('e.status', 'active'))
            ->join('sections as sec', 'sec.id', '=', 'e.section_id')
            ->where('s.school_id', $schoolId)->where('s.branch_id', $branchId)->where('s.status', 'active')
            ->orderBy('s.admission_date')->orderBy('s.admission_no')
            ->get(['s.id', 's.name', 's.family_id', 'sec.class_id'])->groupBy('family_id');

        $structures = DB::table('fee_structures as fs')->join('fee_heads as h', 'h.id', '=', 'fs.fee_head_id')
            ->where('fs.school_id', $schoolId)->where('fs.academic_session_id', $session->id)->where('h.active', true)
            ->get(['fs.class_id', 'fs.amount', 'fs.due_day', 'fs.months', 'h.id as head_id', 'h.name', 'h.frequency', 'h.discountable'])
            ->groupBy('class_id');

        $sibling = DB::table('discount_rules')->where('school_id', $schoolId)->where('kind', 'sibling')
            ->where('active', true)->orderBy('priority')->first();
        $siblingMap = $sibling ? (json_decode($sibling->config, true) ?: []) : [];
        $maxPercent = $sibling->max_total_percent ?? 50;
        $fine = DB::table('fine_rules')->where('school_id', $schoolId)->where('active', true)->first();
        $existing = DB::table('invoices')->where('school_id', $schoolId)->where('period', $period)->pluck('family_id')->flip();
        $seq = DB::table('invoices')->where('school_id', $schoolId)->where('period', $period)->count();
        $today = Carbon::today();
        $created = $skipped = $total = 0;

        foreach ($students as $familyId => $children) {
            if (isset($existing[$familyId])) {
                $skipped++;

                continue;
            }
            $lines = [];
            $dueDay = 10;
            foreach ($children->values() as $i => $child) {
                $childNo = $i + 1;
                $percent = (int) ($siblingMap[(string) $childNo] ?? ($childNo >= 4 ? ($siblingMap['4+'] ?? 0) : 0));
                $own = DB::table('student_discounts')->where('student_id', $child->id)
                    ->where('valid_from', '<=', $today->toDateString())->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today->toDateString()))->get();
                $percent = min($maxPercent, $percent + (int) $own->sum('percent'));
                $fixed = (int) $own->sum('fixed_amount');

                foreach ($structures[$child->class_id] ?? [] as $fs) {
                    $months = $fs->months ? json_decode($fs->months, true) : null;
                    $applies = match ($fs->frequency) {
                        'monthly' => $months === null || in_array($month, $months, true),
                        'yearly', 'per_term' => $months !== null && in_array($month, $months, true),
                        default => false,
                    };
                    if (! $applies) {
                        continue;
                    }
                    $dueDay = (int) $fs->due_day;
                    $lines[] = ['student_id' => $child->id, 'fee_head_id' => $fs->head_id, 'kind' => 'fee',
                        'description' => "{$fs->name} – {$child->name}", 'amount' => (int) $fs->amount, 'discount_rule_id' => null];
                    if ($fs->discountable && $percent > 0) {
                        $lines[] = ['student_id' => $child->id, 'fee_head_id' => $fs->head_id, 'kind' => 'discount',
                            'description' => "Discount {$percent}% – {$child->name}", 'amount' => -intdiv((int) $fs->amount * $percent, 100),
                            'discount_rule_id' => $sibling->id ?? null];
                    }
                }
                if ($fixed > 0) {
                    $lines[] = ['student_id' => $child->id, 'fee_head_id' => null, 'kind' => 'discount',
                        'description' => "Scholarship – {$child->name}", 'amount' => -$fixed, 'discount_rule_id' => null];
                }
            }
            if (! $lines) {
                continue;
            }
            if ($fine && $this->hasOverdue($familyId, $today, (int) $fine->grace_days)) {
                $lines[] = ['student_id' => null, 'fee_head_id' => null, 'kind' => 'fine',
                    'description' => 'Late fee on previous dues', 'amount' => (int) $fine->amount, 'discount_rule_id' => null];
            }

            $invoiceId = (string) Str::uuid();
            $now = now();
            $base = ['school_id' => $schoolId, 'branch_id' => $branchId, 'version' => 1, 'created_by' => $userId,
                'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now];
            DB::transaction(function () use ($invoiceId, $familyId, $branchCode, $period, &$seq, $dueDay, $lines, $base, $schoolId, $branchId, $userId) {
                DB::table('invoices')->insert($base + [
                    'id' => $invoiceId, 'family_id' => $familyId,
                    'number' => sprintf('INV-%s-%s-%05d', $branchCode, $period, ++$seq), 'period' => $period,
                    'issue_date' => Carbon::today()->toDateString(), 'due_date' => Carbon::parse("$period-01")->day(min(28, $dueDay))->toDateString(),
                    'status' => 'issued',
                ]);
                foreach ($lines as $l) {
                    DB::table('invoice_lines')->insert($base + $l + ['id' => (string) Str::uuid(), 'invoice_id' => $invoiceId]);
                }
                $this->postJournal($schoolId, $branchId, $familyId, $invoiceId, $lines, $userId);
            });
            $created++;
            $total += array_sum(array_column($lines, 'amount'));
        }

        return compact('created', 'skipped', 'total');
    }

    private function hasOverdue(string $familyId, Carbon $today, int $graceDays): bool
    {
        return DB::table('invoices')->where('family_id', $familyId)
            ->whereIn('status', ['issued', 'partially_paid'])
            ->where('due_date', '<', $today->copy()->subDays($graceDays)->toDateString())->exists();
    }

    /** Dr family receivable (net), Dr discounts; Cr fee income, Cr fine income. */
    public function postJournal(string $schoolId, string $branchId, string $familyId, string $invoiceId, array $lines, ?string $userId): void
    {
        $sum = fn (string $kind) => abs(array_sum(array_column(array_filter($lines, fn ($l) => $l['kind'] === $kind), 'amount')));
        $fees = $sum('fee');
        $discounts = $sum('discount');
        $fines = $sum('fine');
        $acc = fn (string $code) => DB::table('accounts')->where('school_id', $schoolId)->where('code', $code)->value('id');
        $receivable = DB::table('accounts')->where('school_id', $schoolId)->where('owner_type', 'family')->where('owner_id', $familyId)->value('id');
        if (! $receivable) {
            $receivable = (string) Str::uuid();
            DB::table('accounts')->insert(['id' => $receivable, 'school_id' => $schoolId, 'code' => '1300-'.substr($familyId, 0, 8),
                'name' => 'Receivable – family', 'type' => 'asset', 'owner_type' => 'family', 'owner_id' => $familyId,
                'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $journal = (string) Str::uuid();
        $entries = [[$receivable, $fees + $fines - $discounts, 0], [$acc('5100'), $discounts, 0], [$acc('4100'), 0, $fees], [$acc('4200'), 0, $fines]];
        foreach ($entries as [$account, $dr, $cr]) {
            if ($account && ($dr > 0 || $cr > 0)) {
                DB::table('ledger_entries')->insert(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'branch_id' => $branchId,
                    'journal_id' => $journal, 'account_id' => $account, 'debit' => $dr, 'credit' => $cr, 'entry_date' => now()->toDateString(),
                    'source_type' => 'invoice', 'source_id' => $invoiceId, 'memo' => 'Fee voucher', 'created_by' => $userId, 'created_at' => now()]);
            }
        }
    }
}
