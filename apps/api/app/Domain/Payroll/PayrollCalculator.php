<?php

namespace App\Domain\Payroll;

/**
 * Calculates one payslip from salary structure + locked attendance (docs/decisions.md D5).
 * The server is authoritative: devices may run this for drafts, approval recalculates here.
 *
 * Policy keys (school settings):
 *  - day_basis: 'working_days' | 'fixed_30'
 *  - lates_per_day: e.g. 3 (every 3 lates = 1 day deducted; 0 disables)
 */
final class PayrollCalculator
{
    /**
     * @param array<int, array{name:string, type:'earning'|'deduction', amount:int}> $fixedLines  basic, allowances, fixed deductions (tax, EOBI...)
     * @param array{present:int, late:int, half_day:int, absent:int, leave_unpaid:int, leave_paid:int,
     *              holiday:int, weekly_off:int, incomplete:int, overtime_minutes:int} $att
     * @param array{day_basis:string, lates_per_day:int, overtime_rate_per_hour?:int} $policy
     * @param int $loanInstallment
     * @param int $workingDays  scheduled working days in the month (excludes weekly offs, holidays)
     */
    public function calculate(array $fixedLines, array $att, array $policy, int $loanInstallment, int $workingDays): array
    {
        if ($att['incomplete'] > 0) {
            throw new PayrollBlocked("{$att['incomplete']} day(s) have incomplete punches; resolve corrections before approval.");
        }

        $lines = [];
        $gross = 0;
        foreach ($fixedLines as $l) {
            if ($l['type'] === 'earning') {
                $gross += $l['amount'];
                $lines[] = $l + ['basis' => 'structure'];
            }
        }

        $otRate = $policy['overtime_rate_per_hour'] ?? 0;
        if ($otRate > 0 && $att['overtime_minutes'] > 0) {
            $ot = intdiv($att['overtime_minutes'] * $otRate, 60);
            $gross += $ot;
            $lines[] = ['name' => 'Overtime', 'type' => 'earning', 'amount' => $ot, 'basis' => "{$att['overtime_minutes']} min"];
        }

        $basisDays = $policy['day_basis'] === 'fixed_30' ? 30 : max(1, $workingDays);
        $lateDays = $policy['lates_per_day'] > 0 ? intdiv($att['late'], $policy['lates_per_day']) : 0;
        // half days counted as 0.5; work in half-day units to stay in integers
        $halfUnits = ($att['absent'] + $att['leave_unpaid'] + $lateDays) * 2 + $att['half_day'];
        $attendanceDeduction = intdiv($gross * $halfUnits, $basisDays * 2);

        $deductions = 0;
        if ($attendanceDeduction > 0) {
            $deductions += $attendanceDeduction;
            $lines[] = ['name' => 'Attendance deduction', 'type' => 'deduction', 'amount' => $attendanceDeduction,
                'basis' => sprintf('%d absent, %d unpaid leave, %d half day, %d late-days on %d-day basis',
                    $att['absent'], $att['leave_unpaid'], $att['half_day'], $lateDays, $basisDays)];
        }
        foreach ($fixedLines as $l) {
            if ($l['type'] === 'deduction') {
                $deductions += $l['amount'];
                $lines[] = $l + ['basis' => 'structure'];
            }
        }
        if ($loanInstallment > 0) {
            $deductions += $loanInstallment;
            $lines[] = ['name' => 'Loan installment', 'type' => 'deduction', 'amount' => $loanInstallment, 'basis' => 'loan schedule'];
        }

        return [
            'gross' => $gross,
            'total_deductions' => $deductions,
            'net' => $gross - $deductions,
            'lines' => $lines,
        ];
    }
}
