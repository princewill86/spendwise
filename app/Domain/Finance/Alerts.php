<?php

namespace App\Domain\Finance;

use App\Domain\Platform\Settings;
use App\Jobs\SendFinanceAlert;
use Illuminate\Support\Facades\DB;

final class Alerts
{
    public function send(int $userId, string $key, string $message): void
    {
        $preferences = DB::table('user_preferences')->where('user_id', $userId)->first();
        $preference = match (explode(':', $key, 2)[0]) {
            'budget' => $preferences?->budget_alerts,
            'recurring' => $preferences?->recurring_alerts,
            'goal' => $preferences?->goal_alerts,
            default => true,
        };
        if ($preference !== null && ! $preference) {
            return;
        }

        $created = DB::table('user_alerts')->insertOrIgnore([
            'user_id' => $userId, 'event_key' => $key, 'message' => $message,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($created && $preferences?->email_alerts && app(Settings::class)->enabled('email_alerts', false)) {
            $id = DB::table('user_alerts')->where(['user_id' => $userId, 'event_key' => $key])->value('id');
            SendFinanceAlert::dispatch($id)->afterCommit();
        }
    }

    public function checkBudgets(Plans $plans): void
    {
        DB::table('budgets')->whereNull('archived_at')
            ->where('starts_on', '<=', today()->toDateString())->where('ends_on', '>=', today()->toDateString())
            ->chunkById(100, function ($budgets) use ($plans): void {
                foreach ($budgets as $budget) {
                    $spent = $plans->spending($budget);
                    $limit = bccomp($spent, (string) $budget->amount, Money::SCALE);
                    $threshold = bcdiv(bcmul((string) $budget->amount, (string) $budget->threshold, 6), '100', 6);
                    $event = match (true) {
                        $limit > 0 => 'exceeded',
                        $limit === 0 => 'reached',
                        bccomp($spent, $threshold, 6) >= 0 => 'approaching',
                        default => null,
                    };
                    if ($event !== null) {
                        $this->send($budget->user_id, "budget:{$budget->id}:{$budget->starts_on}:{$event}", match ($event) {
                            'exceeded' => "You exceeded the spending limit for {$budget->name}.",
                            'reached' => "You reached the spending limit for {$budget->name}.",
                            default => "You are approaching the spending limit for {$budget->name}.",
                        });
                    }
                }
            });
    }
}
