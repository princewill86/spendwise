<?php

namespace App\Domain\Finance;

use Illuminate\Validation\ValidationException;

final class Money
{
    public const SCALE = 4;

    public static function validate(string $amount, int $currencyScale, string $field, bool $positive = false): string
    {
        if (! preg_match('/^-?\d{1,16}(?:\.\d{1,4})?$/D', $amount)
            || (str_contains($amount, '.') && strlen(rtrim(explode('.', $amount, 2)[1], '0')) > $currencyScale)
            || ($positive && bccomp($amount, '0', self::SCALE) <= 0)) {
            throw ValidationException::withMessages([$field => 'Enter a valid amount for the account currency.']);
        }

        return bcadd($amount, '0', self::SCALE);
    }
}
