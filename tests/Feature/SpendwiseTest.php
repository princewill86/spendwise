<?php

namespace Tests\Feature;

use App\Domain\Finance\Ledger;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SpendwiseTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->withoutVite();
    }

    public function test_public_pages_and_sitemap_are_available(): void
    {
        $this->get('/')->assertOk()->assertSee('See where your money goes.');
        $this->get('/privacy')->assertOk()->assertSee('Privacy policy');
        $this->get('/sitemap.xml')->assertOk()->assertSee('/privacy')->assertDontSee('/dashboard');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_registration_verification_login_and_logout(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Test User', 'email' => 'example@spendwise.test', 'terms' => '1',
            'password' => 'long unique test passphrase', 'password_confirmation' => 'long unique test passphrase',
        ])->assertRedirect();
        $user = User::where('email', 'example@spendwise.test')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->get('/dashboard')->assertRedirect('/email/verify');
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]))->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get('/dashboard')->assertOk();

        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'long unique test passphrase'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_recovery_updates_credentials(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->post('/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'totally new test password', 'password_confirmation' => 'totally new test password',
        ])->assertSessionHas('status');
        $this->post('/login', ['email' => $user->email, 'password' => 'totally new test password'])->assertRedirect('/dashboard');
    }

    public function test_ledger_balances_are_derived_from_postings_and_edits(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $from = $this->account($user, 'Bank', '100.10');
        $to = $this->account($user, 'Wallet', '0');
        $payload = ['type' => 'transfer', 'description' => 'Move funds', 'account_id' => $from, 'destination_id' => $to, 'amount' => '20.15', 'occurred_on' => now()->toDateString()];

        $this->post('/transactions', $payload)->assertSessionHasNoErrors();
        $balances = app(Ledger::class)->accounts($user->id)->pluck('balance', 'id');
        $this->assertSame('79.9500', $balances[$from]);
        $this->assertSame('20.1500', $balances[$to]);

        $entry = DB::table('transactions')->value('id');
        $this->patch('/transactions/'.$entry, array_replace($payload, ['amount' => '5.20']))->assertSessionHasNoErrors();
        $this->assertSame('94.9000', app(Ledger::class)->accounts($user->id)->pluck('balance', 'id')[$from]);
        $this->delete('/transactions/'.$entry, ['confirm' => true])->assertSessionHasNoErrors();
        $this->assertSame('100.1000', app(Ledger::class)->accounts($user->id)->pluck('balance', 'id')[$from]);
    }

    public function test_members_cannot_use_another_members_accounts(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner, 'Private bank', '0');
        $other = User::factory()->create();
        $this->actingAs($other)->get('/accounts')->assertOk()->assertDontSee('Private bank');
        $this->patch('/accounts/'.$account, ['name' => 'Changed', 'type' => 'bank', 'opening_balance' => '0'])->assertForbidden();
        $this->post('/transactions', [
            'type' => 'expense', 'account_id' => $account, 'description' => 'Unauthorized', 'amount' => '1', 'occurred_on' => now()->toDateString(),
        ])->assertSessionHasErrors('account_id');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_cross_currency_transfers_preserve_original_amounts_and_stay_out_of_cash_flow(): void
    {
        $user = User::factory()->create();
        $from = $this->account($user, 'Bank', '100');
        $this->post('/accounts', ['name' => 'Naira wallet', 'opening_balance' => '0', 'type' => 'wallet', 'currency' => 'NGN'])->assertSessionHasNoErrors();
        $to = DB::table('financial_accounts')->where('currency', 'NGN')->value('id');
        $date = now()->toDateString();
        $transfer = ['type' => 'transfer', 'description' => 'Currency exchange', 'account_id' => $from, 'destination_id' => $to, 'amount' => '10', 'occurred_on' => $date];
        $this->post('/transactions', $transfer)->assertSessionHasErrors('destination_amount');
        $this->post('/transactions', $transfer + ['destination_amount' => '10000.10'])->assertSessionHasNoErrors();
        $this->post('/transactions', ['type' => 'income', 'description' => 'Payment', 'account_id' => $from, 'amount' => '12.10', 'occurred_on' => $date])->assertSessionHasNoErrors();
        $this->post('/transactions', ['type' => 'expense', 'description' => 'Groceries', 'account_id' => $from, 'amount' => '2.25', 'occurred_on' => $date])->assertSessionHasNoErrors();

        $this->get('/dashboard')->assertInertia(fn ($page) => $page->component('Dashboard')
            ->has('monthlyTrend', 1)
            ->where('monthlyTrend.0.currency', 'USD')
            ->where('monthlyTrend.0.income.5', '12.1000')
            ->where('monthlyTrend.0.expenses.5', '2.2500')
            ->where('summary.0.currency', 'NGN')
            ->where('summary.0.balance', '10000.1000')
            ->where('summary.0.cash_flow', '0.0000')
            ->where('summary.1.currency', 'USD')
            ->where('summary.1.balance', '99.8500')
            ->where('summary.1.income', '12.1000')
            ->where('summary.1.expense', '2.2500')
            ->where('summary.1.cash_flow', '9.8500'));
    }

    public function test_account_archiving_preserves_balance_but_blocks_new_transactions(): void
    {
        $user = User::factory()->create();
        $id = $this->account($user, 'Cash', '5.25');
        $this->patch('/accounts/'.$id, ['name' => 'Old cash', 'opening_balance' => '5.25', 'type' => 'cash', 'archived' => true])->assertSessionHasNoErrors();
        $this->assertSame('5.2500', app(Ledger::class)->accounts($user->id)->first()->balance);
        $this->post('/transactions', [
            'type' => 'expense', 'account_id' => $id, 'description' => 'Blocked', 'amount' => '1', 'occurred_on' => now()->toDateString(),
        ])->assertSessionHasErrors('account_id');
    }

    private function account(User $user, string $name, string $balance): int
    {
        $this->actingAs($user)->post('/accounts', [
            'name' => $name, 'opening_balance' => $balance, 'type' => 'bank', 'currency' => 'USD',
        ])->assertSessionHasNoErrors();
        $account = DB::table('financial_accounts')->where('user_id', $user->id)->where('name', $name)->first();
        $this->assertNotNull($account);

        return $account->id;
    }
}
