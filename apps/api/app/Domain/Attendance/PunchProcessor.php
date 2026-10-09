<?php

namespace App\Domain\Attendance;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns raw biometric punches into one attendance day per staff (docs/decisions.md D8).
 *
 * Order of rules:
 *  1. Holiday / weekly off / approved leave decide the day first.
 *  2. Punches are de-duplicated (within $duplicateSeconds).
 *  3. Punches are assigned to the shift day whose window contains them,
 *     so an overnight shift (22:00-06:00) belongs to the date it starts.
 *  4. One punch only => "incomplete" (needs a correction, never guessed).
 *  5. Late / half-day / early-leave / overtime from shift settings.
 *
 * Pure PHP: no framework, so it is unit-testable anywhere.
 */
final class PunchProcessor
{
    public function __construct(
        private readonly DateTimeZone $tz,
        private readonly int $duplicateSeconds = 120,
    ) {}

    /**
     * @param string             $shiftDate  Y-m-d, the date the shift starts
     * @param array{starts_at:string, ends_at:string, grace_minutes:int, half_day_after_minutes:int,
     *              early_leave_grace_minutes:int, window_before_minutes:int, window_after_minutes:int,
     *              weekly_offs:int[]} $shift
     * @param DateTimeImmutable[] $punches   all punches of this staff around the date (any order)
     * @param array{holiday?:bool, leave?:'paid'|'unpaid'|null, half_day_leave?:bool} $context
     * @param array{check_in?:?string, check_out?:?string}|null $approvedCorrection
     */
    public function day(string $shiftDate, array $shift, array $punches, array $context = [], ?array $approvedCorrection = null): AttendanceDay
    {
        [$start, $end] = $this->shiftBounds($shiftDate, $shift);
        $windowOpen = $start->modify("-{$shift['window_before_minutes']} minutes");
        $windowClose = $end->modify("+{$shift['window_after_minutes']} minutes");

        $inWindow = array_values(array_filter(
            $punches,
            fn (DateTimeImmutable $p) => $p >= $windowOpen && $p <= $windowClose,
        ));
        $clean = $this->dedupe($inWindow);

        if (! empty($context['holiday'])) {
            return new AttendanceDay($shiftDate, 'holiday', punchCount: count($clean));
        }
        $weekday = (int) $start->format('w');
        if (in_array($weekday, $shift['weekly_offs'], true)) {
            return new AttendanceDay($shiftDate, 'weekly_off', punchCount: count($clean));
        }
        if (($context['leave'] ?? null) !== null && empty($context['half_day_leave'])) {
            return new AttendanceDay($shiftDate, $context['leave'] === 'paid' ? 'leave_paid' : 'leave_unpaid', punchCount: count($clean));
        }

        $in = $clean[0] ?? null;
        $out = count($clean) > 1 ? $clean[count($clean) - 1] : null;

        if ($approvedCorrection) {
            if (! empty($approvedCorrection['check_in'])) {
                $in = new DateTimeImmutable($approvedCorrection['check_in'], $this->tz);
            }
            if (! empty($approvedCorrection['check_out'])) {
                $out = new DateTimeImmutable($approvedCorrection['check_out'], $this->tz);
            }
        }

        if ($in === null && $out === null) {
            return new AttendanceDay($shiftDate, 'absent');
        }
        if ($in === null || $out === null) {
            return new AttendanceDay($shiftDate, 'incomplete', checkIn: $in ?? $out, punchCount: count($clean));
        }

        $late = max(0, $this->minutes($start, $in));
        $early = max(0, $this->minutes($out, $end));
        $overtime = max(0, $this->minutes($end, $out));

        $status = 'present';
        if ($late > $shift['half_day_after_minutes'] || !empty($context['half_day_leave'])) {
            $status = 'half_day';
        } elseif ($late > $shift['grace_minutes']) {
            $status = 'late';
        }
        if ($status !== 'half_day' && $early > $shift['half_day_after_minutes']) {
            $status = 'half_day';
        }

        return new AttendanceDay(
            $shiftDate,
            $status,
            checkIn: $in,
            checkOut: $out,
            lateMinutes: $late > $shift['grace_minutes'] ? $late : 0,
            earlyLeaveMinutes: $early > $shift['early_leave_grace_minutes'] ? $early : 0,
            overtimeMinutes: $overtime,
            punchCount: count($clean),
        );
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
    public function shiftBounds(string $shiftDate, array $shift): array
    {
        $start = new DateTimeImmutable("$shiftDate {$shift['starts_at']}", $this->tz);
        $end = new DateTimeImmutable("$shiftDate {$shift['ends_at']}", $this->tz);
        if ($end <= $start) {          // overnight shift ends next day
            $end = $end->modify('+1 day');
        }
        return [$start, $end];
    }

    /** @param DateTimeImmutable[] $punches @return DateTimeImmutable[] */
    private function dedupe(array $punches): array
    {
        usort($punches, fn ($a, $b) => $a <=> $b);
        $out = [];
        foreach ($punches as $p) {
            $last = end($out);
            if ($last && ($p->getTimestamp() - $last->getTimestamp()) < $this->duplicateSeconds) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    private function minutes(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
    }
}
