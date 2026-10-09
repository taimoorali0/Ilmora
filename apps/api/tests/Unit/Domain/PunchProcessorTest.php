<?php

namespace Tests\Unit\Domain;

use App\Domain\Attendance\PunchProcessor;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class PunchProcessorTest extends TestCase
{
    private const DAY = ['starts_at' => '08:00', 'ends_at' => '14:00', 'grace_minutes' => 10,
        'half_day_after_minutes' => 90, 'early_leave_grace_minutes' => 10,
        'window_before_minutes' => 120, 'window_after_minutes' => 240, 'weekly_offs' => [0]];

    private function p(string $t): DateTimeImmutable
    {
        return new DateTimeImmutable($t, new DateTimeZone('Asia/Karachi'));
    }

    private function proc(): PunchProcessor
    {
        return new PunchProcessor(new DateTimeZone('Asia/Karachi'));
    }

    public function test_on_time_with_duplicate_punches(): void
    {
        $d = $this->proc()->day('2026-10-12', self::DAY, [
            $this->p('2026-10-12 07:55'), $this->p('2026-10-12 07:55:40'), $this->p('2026-10-12 14:05'),
        ]);
        $this->assertSame('present', $d->status);
        $this->assertSame(2, $d->punchCount);
        $this->assertSame(0, $d->lateMinutes);
    }

    public function test_late_after_grace(): void
    {
        $d = $this->proc()->day('2026-10-12', self::DAY, [$this->p('2026-10-12 08:25'), $this->p('2026-10-12 14:00')]);
        $this->assertSame('late', $d->status);
        $this->assertSame(25, $d->lateMinutes);
    }

    public function test_half_day_when_very_late(): void
    {
        $d = $this->proc()->day('2026-10-12', self::DAY, [$this->p('2026-10-12 09:45'), $this->p('2026-10-12 14:00')]);
        $this->assertSame('half_day', $d->status);
    }

    public function test_single_punch_is_incomplete_not_absent(): void
    {
        $d = $this->proc()->day('2026-10-12', self::DAY, [$this->p('2026-10-12 07:58')]);
        $this->assertSame('incomplete', $d->status);
    }

    public function test_no_punch_is_absent(): void
    {
        $this->assertSame('absent', $this->proc()->day('2026-10-12', self::DAY, [])->status);
    }

    public function test_leave_and_weekly_off_come_first(): void
    {
        $this->assertSame('leave_paid', $this->proc()->day('2026-10-12', self::DAY, [], ['leave' => 'paid'])->status);
        $this->assertSame('weekly_off', $this->proc()->day('2026-10-11', self::DAY, [])->status); // Sunday
        $this->assertSame('holiday', $this->proc()->day('2026-10-12', self::DAY, [], ['holiday' => true])->status);
    }

    public function test_overnight_shift_belongs_to_start_date(): void
    {
        $night = ['starts_at' => '22:00', 'ends_at' => '06:00'] + self::DAY;
        $punches = [$this->p('2026-10-12 21:58'), $this->p('2026-10-13 06:20')];
        $d = $this->proc()->day('2026-10-12', $night, $punches);
        $this->assertSame('present', $d->status);
        $this->assertSame(20, $d->overtimeMinutes);
        // the next date's shift does not steal the morning punch
        $this->assertSame('absent', $this->proc()->day('2026-10-13', $night, $punches)->status);
    }

    public function test_approved_correction_completes_the_day(): void
    {
        $d = $this->proc()->day('2026-10-12', self::DAY, [$this->p('2026-10-12 07:58')], [],
            ['check_out' => '2026-10-12 14:00']);
        $this->assertSame('present', $d->status);
    }
}
