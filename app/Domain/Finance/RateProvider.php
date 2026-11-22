<?php

namespace App\Domain\Finance;

interface RateProvider
{
    public function rate(string $from, string $to, string $date): ?object;
}
