<?php

namespace App\Domain\Finance;

use App\Domain\Platform\Settings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class RateSync
{
    public function __construct(private RateFeed $feed) {}

    public function refresh(Settings $settings): ?int
    {
        $configured = $settings->get('exchange', []);
        if (($configured['provider'] ?? null) !== 'open_exchange_rates' || empty($configured['credential'])) {
            return null;
        }
        $quote = $this->feed->latest(Crypt::decryptString($configured['credential']));
        $codes = DB::table('currencies')->where('enabled', true)->pluck('code')->all();
        $rates = array_intersect_key($quote['rates'], array_flip($codes));
        $records = [];
        foreach ($rates as $from => $base) {
            foreach ($rates as $to => $rate) {
                if ($from === $to) {
                    continue;
                }
                $converted = bcdiv($rate, $base, 13);
                $converted = bcadd($converted, '0.0000000000005', 12);
                if (bccomp($converted, '0', 12) > 0 && bccomp($converted, '1000000000000', 12) < 0) {
                    $records[] = [
                        'from_currency' => $from, 'to_currency' => $to, 'rate' => $converted,
                        'quoted_on' => $quote['date'], 'source' => $quote['source'], 'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }
        DB::transaction(function () use ($records): void {
            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('exchange_rates')->upsert($chunk, ['from_currency', 'to_currency', 'quoted_on', 'source'], ['rate', 'updated_at']);
            }
        });

        return count($records);
    }
}
