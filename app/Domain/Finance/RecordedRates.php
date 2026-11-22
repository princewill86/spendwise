<?php

namespace App\Domain\Finance;

use Illuminate\Support\Facades\DB;

final class RecordedRates implements RateProvider
{
    public function rate(string $from, string $to, string $date): ?object
    {
        return DB::table('exchange_rates')->where(['from_currency' => $from, 'to_currency' => $to])
            ->where('quoted_on', '<=', $date)->latest('quoted_on')->latest('id')->first(['rate', 'quoted_on', 'source']);
    }
}
