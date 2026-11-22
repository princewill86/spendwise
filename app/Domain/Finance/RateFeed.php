<?php

namespace App\Domain\Finance;

interface RateFeed
{
    /**
     * @return array{date: string, rates: array<string, string>, source: string}
     */
    public function latest(string $credential): array;
}
