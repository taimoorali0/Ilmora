<?php

namespace App\Domain\Finance;

/**
 * Device-scoped receipt numbers: B1-D07-000123 (docs/decisions.md D4).
 * Each device owns its own sequence, so offline devices never collide.
 */
final class ReceiptNumber
{
    public static function format(string $branchCode, string $deviceCode, int $sequence): string
    {
        if ($sequence < 1) {
            throw new \InvalidArgumentException('Sequence starts at 1.');
        }
        return sprintf('%s-%s-%06d', strtoupper($branchCode), strtoupper($deviceCode), $sequence);
    }

    /** @return array{branch:string, device:string, sequence:int} */
    public static function parse(string $receipt): array
    {
        if (! preg_match('/^([A-Z0-9]+)-([A-Z0-9]+)-(\d{6,})$/', $receipt, $m)) {
            throw new \InvalidArgumentException("Not a receipt number: $receipt");
        }
        return ['branch' => $m[1], 'device' => $m[2], 'sequence' => (int) $m[3]];
    }
}
