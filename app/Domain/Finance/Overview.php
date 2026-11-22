<?php

namespace App\Domain\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class Overview
{
    public function monthlyTrend(int $userId): array
    {
        $month = CarbonImmutable::today()->startOfMonth();
        $start = $month->subMonths(5);
        $labels = collect(range(0, 5))->map(fn (int $offset) => $start->addMonths($offset)->format('Y-m'));
        $rows = DB::table('ledger_postings as p')->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->where('p.user_id', $userId)->whereNull('t.deleted_at')
            ->whereBetween('t.occurred_on', [$start->toDateString(), $month->endOfMonth()->toDateString()])
            ->whereIn('t.type', ['income', 'expense'])
            ->selectRaw('substring(cast(t.occurred_on as varchar), 1, 7) as month, p.currency, t.type, sum(p.amount) as total')
            ->groupByRaw('substring(cast(t.occurred_on as varchar), 1, 7), p.currency, t.type')->orderBy('p.currency')
            ->get()->groupBy('currency');

        return $rows->map(function (Collection $currencyRows, string $currency) use ($labels): array {
            $amounts = $currencyRows->keyBy(fn (object $row) => $row->month.':'.$row->type);

            return [
                'currency' => $currency,
                'labels' => $labels->all(),
                'income' => $labels->map(fn (string $date) => bcadd((string) ($amounts[$date.':income']->total ?? '0'), '0', Money::SCALE))->all(),
                'expenses' => $labels->map(fn (string $date) => bcsub('0', (string) ($amounts[$date.':expense']->total ?? '0'), Money::SCALE))->all(),
            ];
        })->values()->all();
    }

    public function summarize(int $userId, Collection $accounts, array $filters): array
    {
        $today = CarbonImmutable::today();
        [$from, $to] = match ($filters['period'] ?? 'month') {
            'week' => [$today->startOfWeek(), $today->endOfWeek()],
            'previous' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'year' => [$today->startOfYear(), $today->endOfYear()],
            'custom' => [CarbonImmutable::parse($filters['from']), CarbonImmutable::parse($filters['to'])],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };

        $totals = DB::table('ledger_postings as p')->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->where('p.user_id', $userId)->whereNull('t.deleted_at')
            ->whereBetween('t.occurred_on', [$from->toDateString(), $to->toDateString()])
            ->whereIn('t.type', ['income', 'expense'])->groupBy('p.currency', 't.type')
            ->selectRaw('p.currency, t.type, sum(p.amount) as total')->get()->groupBy('currency');

        $balances = $accounts->groupBy('currency');
        $currencies = $balances->keys()->merge($totals->keys())->unique()->sort()->values();

        return $currencies->map(function (string $currency) use ($balances, $totals): array {
            $accounts = $balances->get($currency, collect());
            $balance = $accounts->reduce(fn (string $value, object $account) => bcadd($value, $account->balance, Money::SCALE), '0.0000');
            $amounts = $totals->get($currency, collect())->pluck('total', 'type');
            $income = bcadd((string) $amounts->get('income', '0'), '0', Money::SCALE);
            $expense = bcsub('0', (string) $amounts->get('expense', '0'), Money::SCALE);

            return [
                'currency' => $currency,
                'balance' => $balance,
                'income' => $income,
                'expense' => $expense,
                'cash_flow' => bcsub($income, $expense, Money::SCALE),
            ];
        })->all();
    }
}
