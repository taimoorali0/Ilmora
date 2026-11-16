<?php

namespace App\Services\Payroll;

use App\Domain\Payroll\PayrollBlocked;
use App\Domain\Payroll\PayrollCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the draft payroll for a branch and month, approves it and marks it paid
 * (docs/decisions.md D5). Drafts can be rebuilt; approval locks attendance.
 */
final class PayrollRun
{
    public function __construct(private readonly PayrollCalculator $calculator) {}

    /** @return array{run_id:string, payslips:int, blocked:array<int,string>, net:int} */
    public function draft(string $schoolId, string $branchId, string $period, ?string $userId = null): array
    {
        $run = DB::table('payroll_runs')->where('branch_id', $branchId)->where('period', $period)->first();
        if ($run && in_array($run->status, ['approved', 'paid'], true)) {
            throw new \DomainException('Payroll for this month is already approved.');
        }
        $now = now();
        $runId = $run->id ?? (string) Str::uuid();
        if (! $run) {
            DB::table('payroll_runs')->insert(['id' => $runId, 'school_id' => $schoolId, 'branch_id' => $branchId,
                'period' => $period, 'status' => 'draft', 'version' => 1, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('payroll_runs')->where('id', $runId)->update(['version' => DB::raw('version + 1'), 'updated_at' => $now]);
        }

        $from = Carbon::parse("$period-01");
        $to = $from->copy()->endOfMonth();
        $holidays = DB::table('holidays')->where('school_id', $schoolId)->where('for_staff', true)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->flip();
        $workingDays = 0;
        for ($d = $from->copy(); $d <= $to; $d->addDay()) {
            if ($d->dayOfWeek !== 0 && ! isset($holidays[$d->toDateString()])) {
                $workingDays++;
            }
        }
        $policy = (json_decode((string) DB::table('schools')->where('id', $schoolId)->value('settings'), true)['payroll'] ?? [])
            + ['day_basis' => 'working_days', 'lates_per_day' => 3];

        $blocked = [];
        $count = $net = 0;
        $staff = DB::table('staff')->where('school_id', $schoolId)->where('branch_id', $branchId)->where('status', '!=', 'left')->get();
        foreach ($staff as $s) {
            $att = ['present' => 0, 'late' => 0, 'half_day' => 0, 'absent' => 0, 'leave_unpaid' => 0, 'leave_paid' => 0,
                'holiday' => 0, 'weekly_off' => 0, 'incomplete' => 0, 'overtime_minutes' => 0];
            foreach (DB::table('staff_attendance_days')->where('staff_id', $s->id)->whereBetween('shift_date', [$from->toDateString(), $to->toDateString()])->get() as $day) {
                $att[$day->status]++;
                $att['overtime_minutes'] += (int) $day->overtime_minutes;
            }
            $lines = [];
            $structure = DB::table('salary_structures as ss')->join('salary_components as c', 'c.id', '=', 'ss.salary_component_id')
                ->where('ss.staff_id', $s->id)->where('ss.effective_from', '<=', $to->toDateString())
                ->where(fn ($q) => $q->whereNull('ss.effective_to')->orWhere('ss.effective_to', '>=', $from->toDateString()))
                ->get(['c.name', 'c.type', 'ss.amount']);
            foreach ($structure as $c) {
                $lines[] = ['name' => $c->name, 'type' => $c->type, 'amount' => (int) $c->amount];
            }
            if (! $lines) {
                $lines[] = ['name' => 'Basic salary', 'type' => 'earning', 'amount' => (int) $s->basic_salary];
                if ($s->allowances > 0) {
                    $lines[] = ['name' => 'Allowances', 'type' => 'earning', 'amount' => (int) $s->allowances];
                }
            }
            $loan = (int) DB::table('staff_loans')->where('staff_id', $s->id)->where('status', 'active')
                ->where('starts_period', '<=', $period)->sum('installment');
            try {
                $slip = $this->calculator->calculate($lines, $att, $policy, $loan, $workingDays);
            } catch (PayrollBlocked $e) {
                $blocked[] = "{$s->name}: {$e->getMessage()}";

                continue;
            }
            $values = ['gross' => $slip['gross'], 'total_deductions' => $slip['total_deductions'], 'net' => $slip['net'],
                'lines' => json_encode($slip['lines']), 'attendance_summary' => json_encode($att), 'updated_at' => $now];
            $existing = DB::table('payslips')->where('payroll_run_id', $runId)->where('staff_id', $s->id)->value('id');
            if ($existing) {
                DB::table('payslips')->where('id', $existing)->update($values + ['version' => DB::raw('version + 1')]);
            } else {
                DB::table('payslips')->insert($values + ['id' => (string) Str::uuid(), 'school_id' => $schoolId,
                    'branch_id' => $branchId, 'payroll_run_id' => $runId, 'staff_id' => $s->id, 'version' => 1, 'created_at' => $now]);
            }
            $count++;
            $net += $slip['net'];
        }

        return ['run_id' => $runId, 'payslips' => $count, 'blocked' => $blocked, 'net' => $net];
    }

    public function approve(string $schoolId, string $runId, string $userId): void
    {
        $run = DB::table('payroll_runs')->where('id', $runId)->where('school_id', $schoolId)->first()
            ?? throw new \DomainException('Payroll run not found.');
        if ($run->status !== 'draft' && $run->status !== 'reviewed') {
            throw new \DomainException('Only a draft payroll can be approved.');
        }
        $from = Carbon::parse("{$run->period}-01");
        DB::transaction(function () use ($run, $from, $userId) {
            DB::table('staff_attendance_days')->where('branch_id', $run->branch_id)
                ->whereBetween('shift_date', [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()])
                ->update(['locked' => true, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('payroll_runs')->where('id', $run->id)->update(['status' => 'approved', 'approved_by' => $userId,
                'approved_at' => now(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        });
    }

    public function markPaid(string $schoolId, string $runId, ?string $userId): void
    {
        $run = DB::table('payroll_runs')->where('id', $runId)->where('school_id', $schoolId)->first()
            ?? throw new \DomainException('Payroll run not found.');
        if ($run->status !== 'approved') {
            throw new \DomainException('Approve the payroll before paying it.');
        }
        DB::transaction(function () use ($run, $schoolId, $userId) {
            $net = (int) DB::table('payslips')->where('payroll_run_id', $run->id)->sum('net');
            DB::table('payslips')->where('payroll_run_id', $run->id)->update(['paid_at' => now(), 'payment_method' => 'cash',
                'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('payroll_runs')->where('id', $run->id)->update(['status' => 'paid', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $journal = (string) Str::uuid();
            foreach ([['5200', $net, 0], ['1100', 0, $net]] as [$code, $dr, $cr]) {
                $account = DB::table('accounts')->where('school_id', $schoolId)->where('code', $code)->value('id');
                if ($account && $net > 0) {
                    DB::table('ledger_entries')->insert(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'branch_id' => $run->branch_id,
                        'journal_id' => $journal, 'account_id' => $account, 'debit' => $dr, 'credit' => $cr, 'entry_date' => now()->toDateString(),
                        'source_type' => 'payroll', 'source_id' => $run->id, 'memo' => "Salaries {$run->period}", 'created_by' => $userId, 'created_at' => now()]);
                }
            }
        });
    }
}
