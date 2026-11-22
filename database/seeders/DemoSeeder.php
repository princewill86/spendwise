<?php

namespace Database\Seeders;

use App\Domain\Finance\Ledger;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Demo data can only be installed in a local environment.');
        }
        $email = 'demo@spendwise.example';
        if (User::where('email', $email)->exists()) {
            $this->command->info('The local demo account already exists.');

            return;
        }
        $password = bin2hex(random_bytes(12));
        DB::transaction(function () use ($email, $password): void {
            $user = User::create(['name' => 'Spendwise Demo', 'email' => $email, 'password' => $password]);
            $user->markEmailAsVerified();
            $now = now();
            $accounts = [];
            foreach ([['Daily banking', 'bank', 'USD', '1250'], ['Cash wallet', 'cash', 'USD', '60'], ['Travel wallet', 'wallet', 'EUR', '220']] as [$name, $type, $currency, $balance]) {
                $accounts[] = DB::table('financial_accounts')->insertGetId([
                    'user_id' => $user->id, 'name' => $name, 'type' => $type, 'currency' => $currency,
                    'opening_balance' => $balance, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $categories = [];
            foreach ([['Pay', 'income'], ['Groceries', 'expense'], ['Housing', 'expense'], ['Transport', 'expense']] as [$name, $type]) {
                $categories[$name] = DB::table('categories')->insertGetId([
                    'user_id' => $user->id, 'name' => $name, 'type' => $type, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $ledger = app(Ledger::class);
            for ($months = 3; $months >= 0; $months--) {
                $date = today()->subMonthsNoOverflow($months)->startOfMonth();
                foreach ([['Pay', 'income', '2400.00'], ['Housing', 'expense', '895.00'], ['Groceries', 'expense', '163.42'], ['Transport', 'expense', '79.25']] as [$name, $type, $amount]) {
                    $ledger->write($user->id, [
                        'type' => $type, 'description' => $name, 'category_id' => $categories[$name], 'account_id' => $accounts[0],
                        'amount' => $amount, 'occurred_on' => $date->toDateString(),
                    ]);
                }
            }
            $ledger->write($user->id, [
                'type' => 'transfer', 'description' => 'Weekend cash', 'account_id' => $accounts[0], 'destination_id' => $accounts[1],
                'amount' => '50.00', 'occurred_on' => today()->toDateString(),
            ]);
            DB::table('budgets')->insert([
                'user_id' => $user->id, 'name' => 'Monthly groceries', 'amount' => '280.00', 'currency' => 'USD',
                'category_id' => $categories['Groceries'], 'period' => 'monthly', 'starts_on' => today()->startOfMonth()->toDateString(),
                'ends_on' => today()->endOfMonth()->toDateString(), 'threshold' => 80, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $goal = DB::table('savings_goals')->insertGetId([
                'user_id' => $user->id, 'name' => 'Emergency fund', 'amount' => '3000.00', 'currency' => 'USD', 'status' => 'active',
                'target_date' => today()->addMonths(8)->toDateString(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('savings_contributions')->insert(['savings_goal_id' => $goal, 'amount' => '1250.00', 'note' => 'Initial tracked savings', 'created_at' => $now]);
            $start = today()->addMonthNoOverflow()->startOfMonth()->toDateString();
            DB::table('recurring_rules')->insert([
                'user_id' => $user->id, 'account_id' => $accounts[0], 'category_id' => $categories['Housing'],
                'type' => 'expense', 'description' => 'Monthly rent', 'amount' => '895.00', 'starts_on' => $start,
                'next_on' => $start, 'interval_unit' => 'months', 'interval_count' => 1, 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });
        $this->command->info("Local demo login: {$email}");
        $this->command->info("Generated demo password: {$password}");
    }
}
