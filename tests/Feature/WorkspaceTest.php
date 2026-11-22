<?php

namespace Tests\Feature;

use App\Domain\Finance\Conversion;
use App\Domain\Finance\Ledger;
use App\Domain\Finance\Plans;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->withoutVite();
    }

    public function test_budget_counts_expenses_and_notifies_once(): void
    {
        [$user, $account] = $this->memberWithAccount();
        $this->post('/budgets', [
            'name' => 'Monthly', 'currency' => 'USD', 'amount' => '50', 'period' => 'monthly',
            'starts_on' => today()->toDateString(), 'threshold' => 80, 'archived' => false,
        ])->assertSessionHasNoErrors();
        $this->post('/transactions', $this->transaction($account, '45.50'))->assertSessionHasNoErrors();
        $this->post('/transactions', array_replace($this->transaction($account, '500'), ['type' => 'income']))->assertSessionHasNoErrors();
        $this->get('/budgets')->assertInertia(fn ($page) => $page->where('budgets.data.0.spent', '45.5000')->where('budgets.data.0.remaining', '4.5000'));

        $this->artisan('spendwise:scheduled')->assertSuccessful();
        $this->artisan('spendwise:scheduled')->assertSuccessful();
        $this->assertDatabaseCount('user_alerts', 1);
        $this->get('/notifications')->assertInertia(fn ($page) => $page->has('alerts.data', 1));
        $this->patch('/notifications', ['action' => 'read'])->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('user_alerts')->where('user_id', $user->id)->value('read_at'));
    }

    public function test_goal_tracks_contributions_separately_and_rejects_excess_withdrawals(): void
    {
        [$user] = $this->memberWithAccount();
        $this->post('/goals', ['name' => 'Emergency fund', 'currency' => 'USD', 'amount' => '100', 'status' => 'active'])->assertSessionHasNoErrors();
        $id = DB::table('savings_goals')->value('id');
        $this->post("/goals/$id/contributions", ['direction' => 'deposit', 'amount' => '125.25'])->assertSessionHasNoErrors();
        $this->post("/goals/$id/contributions", ['direction' => 'withdrawal', 'amount' => '25.15'])->assertSessionHasNoErrors();
        $this->post("/goals/$id/contributions", ['direction' => 'withdrawal', 'amount' => '101'])->assertSessionHasErrors('amount');

        $this->get("/goals?goal=$id")->assertInertia(fn ($page) => $page->where('goals.data.0.saved', '100.1000')->has('history.data', 2));
        $this->assertSame('100.1000', app(Plans::class)->saved($id));
        $this->assertSame('100.0000', app(Ledger::class)->accounts($user->id)->first()->balance);
        $this->assertDatabaseCount('user_alerts', 1);

        $this->actingAs(User::factory()->create())->get("/goals?goal=$id")->assertNotFound();
    }

    public function test_recurring_rule_resumes_and_posts_each_due_occurrence_once(): void
    {
        [$user, $account] = $this->memberWithAccount();
        $today = today()->toDateString();
        $rule = [
            'type' => 'expense', 'description' => 'Rent', 'account_id' => $account, 'amount' => '12.25',
            'starts_on' => $today, 'ends_on' => $today, 'interval_unit' => 'months', 'interval_count' => 1,
            'status' => 'paused',
        ];
        $this->post('/recurring', $rule)->assertSessionHasNoErrors();
        $id = DB::table('recurring_rules')->value('id');
        $this->artisan('spendwise:scheduled')->assertSuccessful();
        $this->assertDatabaseCount('transactions', 0);
        $this->patch("/recurring/$id", array_replace($rule, ['status' => 'active']))->assertSessionHasNoErrors();
        $this->get('/calendar')->assertInertia(fn ($page) => $page->has('rules.data', 1));
        $this->artisan('spendwise:scheduled')->assertSuccessful();
        $this->artisan('spendwise:scheduled')->assertSuccessful();
        $this->assertDatabaseCount('recurring_occurrences', 1);
        $this->assertDatabaseHas('recurring_rules', ['id' => $id, 'status' => 'completed']);
        $this->assertSame('87.7500', app(Ledger::class)->accounts($user->id)->first()->balance);
    }

    public function test_csv_preview_reports_invalid_and_duplicate_rows_and_confirmation_is_idempotent(): void
    {
        [$user, $account] = $this->memberWithAccount();
        $day = today()->toDateString();
        $file = UploadedFile::fake()->createWithContent('ledger.csv', "date,description,amount\n$day,Coffee,-3.25\n$day,Coffee,-3.25\nno,Item,30\n");
        $this->post('/imports', [
            'file' => $file, 'account_id' => $account, 'date_format' => 'Y-m-d',
            'date_column' => 'date', 'description_column' => 'description', 'amount_column' => 'amount',
        ])->assertSessionHasNoErrors();
        $id = DB::table('transaction_imports')->value('id');
        $this->assertNotNull($id);
        $this->assertDatabaseCount('transactions', 0);
        $this->get("/imports?draft=$id")->assertInertia(fn ($page) => $page->where('draft.accepted_count', 1)->where('draft.rejected_count', 2));

        $this->actingAs(User::factory()->create())->post("/imports/$id/confirm", ['confirm' => true])->assertNotFound();
        $this->actingAs($user)->post("/imports/$id/confirm", ['confirm' => true])->assertRedirect('/imports');
        $this->post("/imports/$id/confirm", ['confirm' => true])->assertRedirect('/imports');
        $this->assertDatabaseCount('transactions', 1);
        $this->get('/reports')->assertInertia(fn ($page) => $page->where('spending.0.name', 'Uncategorized')->has('months', 1));
        $csv = $this->get('/exports/transactions')->assertOk()->streamedContent();
        $this->assertStringContainsString('Coffee', $csv);
        $this->assertStringContainsString('-3.25', $csv);
    }

    public function test_platform_settings_exclude_secrets_and_block_disabled_routes(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($member)->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/admin')->assertInertia(fn ($page) => $page->component('Administration')->has('registrationTrend.labels', 30)->where('registrationTrend.counts.29', 2));
        $this->patch('/admin/settings/mail', [
            'driver' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587, 'scheme' => 'smtp',
            'password' => 'secret-test-password', 'from_address' => 'test@example.test', 'from_name' => 'Spendwise',
        ])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('secret-test-password', DB::table('platform_settings')->where('key', 'mail')->value('value'));
        $this->get('/admin/mail')->assertOk()->assertDontSee('encrypted_password')->assertDontSee('secret-test-password');

        $this->patch('/admin/settings/features', [
            'registration' => true, 'goals' => false, 'recurring' => true, 'imports' => true, 'reports' => true,
            'multi_currency' => true, 'conversion' => false, 'email_alerts' => false, 'mfa' => true, 'pdf_exports' => true,
        ])->assertSessionHasNoErrors();
        $this->actingAs($member)->get('/goals')->assertNotFound();
        $this->post('/goals', [])->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_admin_approves_deletion_and_personal_export_remains_owner_only(): void
    {
        [$member, $account] = $this->memberWithAccount();
        $this->post('/transactions', $this->transaction($account, '10'))->assertSessionHasNoErrors();
        $this->patch('/settings/deletion', ['request_deletion' => true, 'password' => 'password'])->assertSessionHasNoErrors();
        $export = $this->get('/settings/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Groceries', $export);

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin)->get('/settings/export')->assertOk();
        $this->assertStringNotContainsString('Groceries', $this->get('/settings/export')->streamedContent());
        $this->patch('/admin/users/'.$member->id, ['action' => 'delete'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('ledger_postings', 0);
    }

    public function test_conversion_preserves_sources_rounds_once_and_rejects_partial_totals(): void
    {
        $today = today()->toDateString();
        DB::table('exchange_rates')->insert(['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => '1.125', 'quoted_on' => $today, 'source' => 'Test quote']);
        $conversion = app(Conversion::class);
        $rows = [['currency' => 'USD', 'balance' => '1.00'], ['currency' => 'EUR', 'balance' => '1.00']];
        $quote = $conversion->balances($rows, 'USD', $today);
        $this->assertSame('2.13', $quote['total']);
        $this->assertSame('Test quote', $quote['quotes'][0]['source']);
        $this->assertNull($conversion->balances([...$rows, ['currency' => 'GBP', 'balance' => '1']], 'USD', $today)['total']);
        $this->assertSame('-1.13', $conversion->balances([['currency' => 'EUR', 'balance' => '-1']], 'USD', $today)['total']);
    }

    public function test_reconciliation_and_receipts_are_scoped_to_the_owner(): void
    {
        Storage::fake('local');
        [$user, $account] = $this->memberWithAccount();
        $this->post("/accounts/$account/reconcile", ['balance' => '88.55'])->assertSessionHasNoErrors();
        $this->assertSame('88.5500', app(Ledger::class)->accounts($user->id)->first()->balance);
        $entry = DB::table('transactions')->where('type', 'adjustment')->value('id');
        $this->post("/transactions/$entry/attachment", ['file' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n%%EOF")])->assertSessionHasNoErrors();
        $receipt = DB::table('transaction_attachments')->first();
        $this->assertNotNull($receipt);
        Storage::disk('local')->assertExists($receipt->path);
        $this->get("/transactions/$entry/attachment")->assertOk()->assertDownload('receipt.pdf');
        $this->actingAs(User::factory()->create())->get("/transactions/$entry/attachment")->assertForbidden();
        $this->actingAs($user)->delete("/transactions/$entry/attachment")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transaction_attachments', 0);
    }

    public function test_administration_publishes_content_and_applies_indexing_controls(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin)->patch('/admin/content/about', [
            'title' => 'About our workspace', 'meta_description' => 'A configured description', 'published' => true,
            'content' => ['sections' => [['title' => 'Context', 'body' => 'This change is published.']]],
        ])->assertSessionHasNoErrors();
        $this->get('/about')->assertOk()->assertSee('About our workspace');
        $this->patch('/admin/settings/seo', ['indexing' => false, 'sitemap' => true, 'title' => 'Configured site title'])->assertSessionHasNoErrors();
        $this->get('/')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('Configured site title');
        $this->get('/sitemap.xml')->assertNotFound();
        $this->get('/robots.txt')->assertSee('Disallow: /');
    }

    public function test_feed_refresh_preserves_decimal_quotes_and_keeps_credentials_private(): void
    {
        $key = str_repeat('a', 32);
        $date = now()->timestamp;
        Http::fake(['openexchangerates.org/*' => Http::response('{"base":"USD","timestamp":'.$date.', "rates":{"EUR":0.812345678901,"GBP":0.754}}')]);
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin)->patch('/admin/exchange-provider', ['provider' => 'open_exchange_rates', 'credential' => $key])->assertSessionHasNoErrors();
        $this->post('/admin/exchange-rates/refresh')->assertSessionHasNoErrors();
        $this->get('/admin/currency')->assertOk()->assertDontSee($key);
        $this->assertDatabaseCount('exchange_rates', 6);
        $rate = app(Conversion::class)->balances([['currency' => 'USD', 'balance' => '100']], 'EUR', today()->toDateString());
        $this->assertSame('81.23', $rate['total']);
        $this->assertSame('Open Exchange Rates', $rate['quotes'][0]['source']);
    }

    public function test_optional_second_factor_requires_confirmation_and_challenges_login(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/user/confirm-password', ['password' => 'password'])->assertSuccessful();
        $this->postJson('/user/two-factor-authentication')->assertSuccessful();
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
        $secret = $this->getJson('/user/two-factor-secret-key')->assertOk()->json('secretKey');
        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => $code])->assertSuccessful();
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());

        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $recovery = $user->fresh()->recoveryCodes()[0];
        $this->post('/two-factor-challenge', ['recovery_code' => $recovery])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($recovery, $user->fresh()->recoveryCodes());
    }

    public function test_admin_can_create_publish_and_link_a_custom_page(): void
    {
        $page = [
            'slug' => 'getting-started-guide', 'title' => 'Getting started guide',
            'content' => ['sections' => [['title' => 'First steps', 'body' => 'Add an account to get started.']]],
            'published' => false,
        ];
        $member = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($member)->post('/admin/content', $page)->assertForbidden();
        $this->actingAs($admin)->post('/admin/content', $page)->assertSessionHasNoErrors();
        $this->get('/pages/getting-started-guide')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('getting-started-guide');
        $this->post('/admin/content', array_replace($page, ['slug' => 'about']))->assertSessionHasErrors('slug');
        $this->post('/admin/content', $page)->assertSessionHasErrors('slug');

        $this->patch('/admin/content/getting-started-guide', array_replace($page, ['published' => true]))->assertSessionHasNoErrors();
        $this->get('/pages/getting-started-guide')->assertOk()->assertSee('Add an account to get started.');
        $this->get('/sitemap.xml')->assertSee('/pages/getting-started-guide');
        $this->patch('/admin/navigation', ['header' => [], 'footer' => [['label' => 'Guide', 'href' => '/pages/getting-started-guide']]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('currencies', ['code' => 'CAD', 'decimal_places' => 2, 'enabled' => true]);
    }

    public function test_social_sharing_image_can_be_uploaded_and_removed(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin)->post('/admin/branding/sharing', [
            'image' => UploadedFile::fake()->createWithContent('sharing.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2yoAAAAASUVORK5CYII=')),
        ])->assertSessionHasNoErrors();
        $this->get('/')->assertOk()->assertSee('/branding/sharing');
        $this->get('/branding/sharing')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->delete('/admin/branding/sharing')->assertSessionHasNoErrors();
        $this->get('/branding/sharing')->assertNotFound();
    }

    private function memberWithAccount(): array
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/accounts', ['name' => 'Cash', 'type' => 'cash', 'currency' => 'USD', 'opening_balance' => '100'])->assertSessionHasNoErrors();

        return [$user, DB::table('financial_accounts')->where('user_id', $user->id)->value('id')];
    }

    private function transaction(int $account, string $amount): array
    {
        return ['type' => 'expense', 'account_id' => $account, 'description' => 'Groceries', 'amount' => $amount, 'occurred_on' => today()->toDateString()];
    }
}
