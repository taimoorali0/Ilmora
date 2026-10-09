<?php

namespace App\Domain\Attendance;

use DateTimeImmutable;

final class AttendanceDay
{
    public function __construct(
        public readonly string $shiftDate,
        public readonly string $status,
        public readonly ?DateTimeImmutable $checkIn = null,
        public readonly ?DateTimeImmutable $checkOut = null,
        public readonly int $lateMinutes = 0,
        public readonly int $earlyLeaveMinutes = 0,
        public readonly int $overtimeMinutes = 0,
        public readonly int $punchCount = 0,
    ) {}
}
