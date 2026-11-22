<?php

namespace App\Domain\Finance;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class Plans
{
    public function spending(object $budget): string
    {
        // Each expense owns one posting. Transfers and adjustments never affect budget progress.
        $spent = DB::table('ledger_postings as p')->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->where('p.user_id', $budget->user_id)->where('p.currency', $budget->currency)
            ->where('t.type', 'expense')->whereNull('t.deleted_at')
            ->whereBetween('t.occurred_on', [$budget->starts_on, $budget->ends_on])
            ->when($budget->category_id, fn (Builder $query, int $category) => $query->where('t.category_id', $category))
            ->sum('p.amount');

        return bcsub('0', (string) $spent, Money::SCALE);
    }

    public function contributions(int $goalId): Builder
    {
        return DB::table('savings_contributions')->where('savings_goal_id', $goalId);
    }

    public function saved(int $goalId): string
    {
        return bcadd((string) $this->contributions($goalId)->sum('amount'), '0', Money::SCALE);
    }

    public function budgetProgress(object $budget): object
    {
        $budget->spent = $this->spending($budget);
        $budget->remaining = bcsub((string) $budget->amount, $budget->spent, Money::SCALE);
        $budget->percent = bcmul(bcdiv($budget->spent, (string) $budget->amount, 6), '100', 2);

        return $budget;
    }

    public function goalProgress(object $goal): object
    {
        $goal->saved = $this->saved($goal->id);
        $goal->percent = bcmul(bcdiv($goal->saved, (string) $goal->amount, 6), '100', 2);

        return $goal;
    }
}
