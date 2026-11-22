<?php

use App\Domain\Finance\Alerts;
use App\Domain\Finance\Ledger;
use App\Domain\Finance\Plans;
use App\Domain\Finance\RateSync;
use App\Domain\Finance\Recurrence;
use App\Domain\Platform\Settings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('spendwise:scheduled', function (Ledger $ledger, Recurrence $recurrence, Alerts $alerts, Plans $plans): void {
    if (app(Settings::class)->enabled('recurring')) {
        $recurrence->run($ledger, $alerts, CarbonImmutable::today());
    }
    $alerts->checkBudgets($plans);
})->purpose('Post due recurring transactions and evaluate budget alerts');

Schedule::command('spendwise:scheduled')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('spendwise:admin {email}', function (string $email): void {
    $user = User::query()->where('email', strtolower($email))->firstOrFail();
    if (! $user->hasVerifiedEmail() || $user->suspended_at) {
        $this->error('The account must be active and verified.');

        return;
    }
    $user->forceFill(['role' => 'admin'])->save();
    $this->info('Platform administrator access granted.');
})->purpose('Grant platform administration to an existing verified member');

Artisan::command('spendwise:rates', function (RateSync $rates, Settings $settings): int {
    if (! $settings->enabled('conversion', false)) {
        return 0;
    }
    try {
        $count = $rates->refresh($settings);
        $this->info($count === null ? 'Provider not configured.' : "Saved {$count} exchange-rate pairs.");

        return 0;
    } catch (Throwable) {
        $this->error('The exchange-rate refresh failed. Check provider configuration.');

        return 1;
    }
})->purpose('Refresh configured exchange-rate quotes');

Schedule::command('spendwise:rates')->dailyAt('08:00')->withoutOverlapping();

Schedule::call(function (): void {
    $days = (int) (app(Settings::class)->get('finance', [])['draft_days'] ?? 14);
    DB::table('transaction_imports')->whereNull('confirmed_at')->where('created_at', '<', now()->subDays($days))->delete();
})->name('spendwise:discard-stale-import-previews')->dailyAt('02:30')->withoutOverlapping();
