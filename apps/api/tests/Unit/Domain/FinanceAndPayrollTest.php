<?php

namespace Tests\Unit\Domain;

use App\Domain\Finance\PaymentAllocator;
use App\Domain\Finance\ReceiptNumber;
use App\Domain\Payroll\PayrollBlocked;
use App\Domain\Payroll\PayrollCalculator;
use PHPUnit\Framework\TestCase;

class FinanceAndPayrollTest extends TestCase
{
    public function test_allocation_pays_oldest_period_and_fines_first_then_credit(): void
    {
        $r = (new PaymentAllocator())->allocate(6000, [
            ['line_id' => 'c', 'invoice_id' => 'oct', 'period' => '2026-10', 'kind' => 'fee', 'outstanding' => 3000],
            ['line_id' => 'b', 'invoice_id' => 'sep', 'period' => '2026-09', 'kind' => 'fee', 'outstanding' => 2500],
            ['line_id' => 'a', 'invoice_id' => 'sep', 'period' => '2026-09', 'kind' => 'fine', 'outstanding' => 200],
        ]);
        $this->assertSame(['a', 'b', 'c'], array_column($r['allocations'], 'line_id'));
        $this->assertSame([200, 2500, 3000], array_column($r['allocations'], 'amount'));
        $this->assertSame(300, $r['credit']);
    }

    public function test_receipt_numbers_are_device_scoped(): void
    {
        $this->assertSame('B1-D07-000123', ReceiptNumber::format('b1', 'd07', 123));
        $this->assertSame(['branch' => 'B1', 'device' => 'D07', 'sequence' => 123], ReceiptNumber::parse('B1-D07-000123'));
    }

    private function att(array $o = []): array
    {
        return $o + ['present' => 20, 'late' => 0, 'half_day' => 0, 'absent' => 0, 'leave_unpaid' => 0,
            'leave_paid' => 0, 'holiday' => 0, 'weekly_off' => 4, 'incomplete' => 0, 'overtime_minutes' => 0];
    }

    public function test_payroll_deducts_absent_half_day_and_late_rule(): void
    {
        $lines = [
            ['name' => 'Basic', 'type' => 'earning', 'amount' => 40000],
            ['name' => 'Conveyance', 'type' => 'earning', 'amount' => 4000],
            ['name' => 'EOBI', 'type' => 'deduction', 'amount' => 370],
        ];
        // 1 absent + 1 half day + 6 lates (=2 days) => 3.5 days on 22 working days
        $r = (new PayrollCalculator())->calculate($lines, $this->att(['absent' => 1, 'half_day' => 1, 'late' => 6]),
            ['day_basis' => 'working_days', 'lates_per_day' => 3], 3000, 22);
        $this->assertSame(44000, $r['gross']);
        $this->assertSame(7000 + 370 + 3000, $r['total_deductions']); // 44000*3.5/22 = 7000
        $this->assertSame(44000 - 10370, $r['net']);
    }

    public function test_payroll_blocked_while_punches_incomplete(): void
    {
        $this->expectException(PayrollBlocked::class);
        (new PayrollCalculator())->calculate([['name' => 'Basic', 'type' => 'earning', 'amount' => 30000]],
            $this->att(['incomplete' => 1]), ['day_basis' => 'fixed_30', 'lates_per_day' => 3], 0, 26);
    }
}
