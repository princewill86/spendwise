<?php

namespace App\Domain\Finance;

use Illuminate\Support\Facades\DB;

final class Conversion
{
    public function __construct(private RateProvider $provider) {}

    public function balances(array $totals, string $display, string $date): array
    {
        $precision = (int) DB::table('currencies')->where('code', $display)->value('decimal_places');
        $total = '0';
        $quotes = [];
        $missing = [];
        foreach ($totals as $row) {
            if ($row['currency'] === $display) {
                $total = bcadd($total, $row['balance'], 16);

                continue;
            }
            $quote = $this->provider->rate($row['currency'], $display, $date);
            if (! $quote) {
                $missing[] = $row['currency'];

                continue;
            }

            $rate = (string) $quote->rate;
            $total = bcadd($total, bcmul($row['balance'], $rate, 16), 16);
            $quotes[] = ['currency' => $row['currency'], 'rate' => $rate, 'date' => $quote->quoted_on, 'source' => $quote->source];
        }

        // Round once after summing full-precision estimates, with halves away from zero.
        $half = $precision === 0 ? '0.5' : '0.'.str_repeat('0', $precision).'5';
        $rounded = bccomp($total, '0', 16) < 0 ? bcsub($total, $half, $precision) : bcadd($total, $half, $precision);

        return ['currency' => $display, 'total' => $missing ? null : $rounded, 'quotes' => $quotes, 'missing' => $missing, 'valued_on' => $date];
    }
}
