<?php

namespace App\Domain\Finance;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class OpenExchangeRates implements RateFeed
{
    public function latest(string $credential): array
    {
        try {
            $response = Http::acceptJson()->withHeaders(['Authorization' => 'Token '.$credential])
                ->withoutRedirecting()->connectTimeout(4)->timeout(8)->get('https://openexchangerates.org/api/latest.json');
            if (! $response->successful() || strlen($response->body()) > 250000) {
                throw new RuntimeException;
            }
            // Tokenize numbers outside quoted strings before decoding to preserve the quoted decimal.
            $json = preg_replace_callback(
                '~"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?(?:[eE][+-]?[0-9]+)?~',
                fn ($match) => '"'.$match[0].'"',
                $response->body(),
            );
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $timestamp = $data['timestamp'] ?? '';
            if (($data['base'] ?? null) !== 'USD' || ! is_string($timestamp) || ! preg_match('/^[0-9]{10}$/D', $timestamp)
                || (int) $timestamp > now()->addMinutes(10)->timestamp || ! is_array($data['rates'] ?? null)) {
                throw new RuntimeException;
            }
            $rates = ['USD' => '1'];
            foreach ($data['rates'] as $code => $rate) {
                if (is_string($code) && preg_match('/^[A-Z]{3}$/D', $code) && is_string($rate)
                    && preg_match('/^\d{1,12}(?:\.\d{1,16})?$/D', $rate) && bccomp($rate, '0', 16) > 0) {
                    $rates[$code] = $rate;
                }
            }

            return ['date' => gmdate('Y-m-d', (int) $timestamp), 'rates' => $rates, 'source' => 'Open Exchange Rates'];
        } catch (Throwable) {
            throw new RuntimeException('The exchange-rate provider did not return usable quotes.');
        }
    }
}
