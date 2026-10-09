<?php

namespace App\Domain\Finance;

/**
 * Splits a payment across open invoice lines (docs/decisions.md D3).
 * Order: oldest period first; inside a period fines first, then fees.
 * Whatever is left becomes family credit and is flagged for reconciliation
 * (two offline devices may have collected against the same balance).
 */
final class PaymentAllocator
{
    /**
     * @param int $amount  rupees received
     * @param array<int, array{line_id:string, invoice_id:string, period:string, kind:string, outstanding:int}> $openLines
     * @return array{allocations: array<int, array{line_id:string, invoice_id:string, amount:int}>, credit:int}
     */
    public function allocate(int $amount, array $openLines): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be positive.');
        }

        usort($openLines, function ($a, $b) {
            return [$a['period'], $a['kind'] === 'fine' ? 0 : 1, $a['line_id']]
                <=> [$b['period'], $b['kind'] === 'fine' ? 0 : 1, $b['line_id']];
        });

        $left = $amount;
        $allocations = [];
        foreach ($openLines as $line) {
            if ($left === 0) {
                break;
            }
            if ($line['outstanding'] <= 0) {
                continue;
            }
            $take = min($left, $line['outstanding']);
            $allocations[] = ['line_id' => $line['line_id'], 'invoice_id' => $line['invoice_id'], 'amount' => $take];
            $left -= $take;
        }

        return ['allocations' => $allocations, 'credit' => $left];
    }
}
