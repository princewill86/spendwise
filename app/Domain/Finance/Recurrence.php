<?php

namespace App\Domain\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Recurrence
{
    public function run(Ledger $ledger, Alerts $alerts, CarbonImmutable $today): void
    {
        DB::table('recurring_rules')->where('status', 'active')
            ->where('next_on', '>', $today->toDateString())->where('next_on', '<=', $today->addDays(3)->toDateString())
            ->chunkById(100, function ($rules) use ($alerts): void {
                foreach ($rules as $rule) {
                    $alerts->send($rule->user_id, "recurring:{$rule->id}:upcoming:{$rule->next_on}", "{$rule->description} is scheduled for {$rule->next_on}.");
                }
            });
        DB::table('recurring_rules')->where('status', 'active')->where('next_on', '<=', $today->toDateString())
            ->chunkById(100, function ($rules) use ($ledger, $alerts, $today): void {
                foreach ($rules as $rule) {
                    // A per-run bound keeps an old rule from monopolizing the scheduled job.
                    for ($attempt = 0; $attempt < 20; $attempt++) {
                        if (! $this->postDue($rule->id, $ledger, $alerts, $today)) {
                            break;
                        }
                    }
                }
            });
    }

    private function postDue(int $id, Ledger $ledger, Alerts $alerts, CarbonImmutable $today): bool
    {
        return DB::transaction(function () use ($id, $ledger, $alerts, $today): bool {
            $rule = DB::table('recurring_rules')->where('id', $id)->lockForUpdate()->first();
            if (! $rule || $rule->status !== 'active' || $rule->next_on > $today->toDateString()) {
                return false;
            }
            if ($rule->ends_on && $rule->next_on > $rule->ends_on) {
                DB::table('recurring_rules')->where('id', $id)->update(['status' => 'completed', 'updated_at' => now()]);

                return false;
            }

            $date = CarbonImmutable::parse($rule->next_on);
            if (! DB::table('recurring_occurrences')->where(['recurring_rule_id' => $id, 'scheduled_on' => $rule->next_on])->exists()) {
                try {
                    $entry = $ledger->write($rule->user_id, [
                        'occurred_on' => $rule->next_on, 'account_id' => $rule->account_id, 'category_id' => $rule->category_id,
                        'amount' => (string) $rule->amount, 'type' => $rule->type, 'description' => $rule->description,
                    ]);
                } catch (ValidationException) {
                    DB::table('recurring_rules')->where('id', $id)->update(['status' => 'paused', 'updated_at' => now()]);
                    $alerts->send($rule->user_id, "recurring:{$id}:blocked:{$rule->next_on}", "Review the account or category for {$rule->description}.");

                    return false;
                }
                DB::table('recurring_occurrences')->insert([
                    'recurring_rule_id' => $id, 'scheduled_on' => $rule->next_on, 'transaction_id' => $entry->id,
                ]);
                $alerts->send($rule->user_id, "recurring:{$id}:{$rule->next_on}", "Recorded scheduled transaction: {$rule->description}.");
            }

            $anchor = CarbonImmutable::parse($rule->starts_on);
            $month = $date->startOfMonth()->addMonths($rule->interval_count);
            $next = match ($rule->interval_unit) {
                'days' => $date->addDays($rule->interval_count),
                'weeks' => $date->addWeeks($rule->interval_count),
                'months' => $month->addDays(min($anchor->day, $month->daysInMonth) - 1),
            };
            DB::table('recurring_rules')->where('id', $id)->update([
                'next_on' => $next->toDateString(),
                'status' => $rule->ends_on && $next->toDateString() > $rule->ends_on ? 'completed' : 'active',
                'updated_at' => now(),
            ]);

            return true;
        }, attempts: 3);
    }
}
