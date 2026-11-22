<?php

namespace App\Http\Controllers;

use App\Domain\Finance\RateSync;
use App\Domain\Platform\Audit;
use App\Domain\Platform\PlatformMail;
use App\Domain\Platform\Settings;
use App\Jobs\SendFinanceAlert;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminController extends Controller
{
    public const FEATURES = ['registration', 'goals', 'recurring', 'imports', 'reports', 'multi_currency', 'conversion', 'email_alerts', 'mfa', 'pdf_exports'];

    public function index(Request $request, Settings $settings, string $section = 'overview'): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'state' => ['nullable', Rule::in(['active', 'suspended', 'deletion'])]]);
        $props = match ($section) {
            'overview' => [
                'registrationTrend' => $this->registrationTrend(),
                'counts' => [
                    'Members' => User::count(), 'Active members' => User::whereNull('suspended_at')->count(),
                    'New registrations (7 days)' => User::where('created_at', '>=', now()->subDays(7))->count(),
                    'Verified members' => User::whereNotNull('email_verified_at')->count(), 'Suspended members' => User::whereNotNull('suspended_at')->count(),
                    'Financial accounts' => DB::table('financial_accounts')->count(), 'Transactions' => DB::table('transactions')->whereNull('deleted_at')->count(),
                    'Budgets' => DB::table('budgets')->count(), 'Failed emails' => DB::table('email_logs')->where('status', 'failed')->count(),
                ],
                'audit' => DB::table('audit_logs')->latest('id')->limit(8)->get(),
            ],
            'users' => [
                'users' => User::query()
                    ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->whereLike('email', "%{$search}%")->orWhereLike('name', "%{$search}%")))
                    ->when(($filters['state'] ?? '') === 'active', fn ($q) => $q->whereNull('suspended_at'))
                    ->when(($filters['state'] ?? '') === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
                    ->when(($filters['state'] ?? '') === 'deletion', fn ($q) => $q->whereNotNull('deletion_requested_at'))
                    ->latest('id')->paginate(20, ['id', 'name', 'email', 'role', 'created_at', 'suspended_at', 'deletion_requested_at', 'email_verified_at'])->withQueryString(),
            ],
            'audit' => ['logs' => DB::table('audit_logs')->when($filters['q'] ?? null, fn ($query, $search) => $query->whereLike('action', "%{$search}%"))->latest('id')->paginate(30)->withQueryString()],
            'navigation' => ['navigation' => $settings->get('navigation', [])],
            'content' => ['pages' => DB::table('content_pages')->orderBy('slug')->get()->map(fn ($page) => (array) $page + ['url' => PublicSiteController::pageUrl($page->slug)])],
            'currency' => ['exchange' => Arr::except($settings->get('exchange', []), 'credential'), 'currencies' => DB::table('currencies')->orderBy('code')->get(), 'rates' => DB::table('exchange_rates')->latest('quoted_on')->latest('id')->limit(30)->get()],
            'mail' => [
                'values' => Arr::except($settings->get('mail', []), ['encrypted_password']),
                'templates' => DB::table('email_templates')->orderBy('key')->get(),
                'mailLogs' => DB::table('email_logs')->latest('id')->paginate(20),
            ],
            'seo' => ['values' => Arr::except($settings->get('seo', []), 'image'), 'sharingImage' => BrandingController::sharingUrl($settings)],
            'features' => ['values' => collect(self::FEATURES)->mapWithKeys(fn ($key) => [$key => $settings->enabled($key, ! in_array($key, ['conversion', 'email_alerts'], true))])->all()],
            default => ['values' => $settings->get($section, []), 'currencies' => DB::table('currencies')->where('enabled', true)->orderBy('code')->get()],
        };

        return Inertia::render('Administration', $props + ['section' => $section, 'filters' => $filters, 'viewer' => $request->user()->id]);
    }

    private function registrationTrend(): array
    {
        $start = today()->subDays(29);
        $dates = collect(range(0, 29))->map(fn (int $offset) => $start->copy()->addDays($offset)->toDateString());
        $counts = User::query()->where('created_at', '>=', $start)->where('created_at', '<', today()->addDay())
            ->selectRaw('date(created_at) as day, count(*) as total')->groupByRaw('date(created_at)')->pluck('total', 'day');

        return ['labels' => $dates->all(), 'counts' => $dates->map(fn (string $date) => (int) ($counts[$date] ?? 0))->all()];
    }

    public function settings(Request $request, Settings $settings, string $section): RedirectResponse
    {
        $rules = match ($section) {
            'general' => [
                'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:400'],
                'contact' => ['nullable', 'email', 'max:254'], 'support' => ['nullable', 'email', 'max:254'],
                'currency' => ['nullable', 'string', Rule::exists('currencies', 'code')->where('enabled', true)],
                'public_site' => ['sometimes', 'boolean'], 'maintenance' => ['sometimes', 'boolean'],
                'site_url' => ['nullable', 'url:https', 'max:255'],
                'theme' => ['required', Rule::in(['system', 'light', 'dark'])], 'timezone' => ['required', 'timezone'],
            ],
            'security' => [
                'password_min' => ['required', 'integer', 'between:12,64'],
                'login_limit' => ['required', 'integer', 'between:2,20'],
                'session_lifetime' => ['required', 'integer', 'between:15,480'],
                'upload_limit' => ['sometimes', 'integer', 'between:256,10240'],
            ],
            'seo' => [
                'title' => ['nullable', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:200'],
                'canonical' => ['nullable', 'url:https', 'max:255'], 'indexing' => ['required', 'boolean'],
                'sitemap' => ['required', 'boolean'],
            ],
            'finance' => [
                'import_rows' => ['required', 'integer', 'between:10,500'],
                'draft_days' => ['required', 'integer', 'between:1,90'],
            ],
            'features' => array_fill_keys(self::FEATURES, ['required', 'boolean']),
            'mail' => [
                'driver' => ['required', Rule::in(['smtp', 'sendmail', 'log'])],
                'host' => ['required_if:driver,smtp', 'nullable', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.:-]+$/D'],
                'port' => ['required_if:driver,smtp', 'nullable', 'integer', 'between:1,65535'],
                'scheme' => ['required_if:driver,smtp', 'nullable', Rule::in(['smtp', 'smtps'])],
                'username' => ['nullable', 'string', 'max:255'], 'password' => ['nullable', 'string', 'max:1000'],
                'clear_password' => ['sometimes', 'boolean'], 'from_address' => ['required', 'email', 'max:254'],
                'from_name' => ['required', 'string', 'max:120'], 'reply_to' => ['nullable', 'email', 'max:254'],
            ],
        };
        $data = $request->validate($rules);
        if ($section === 'seo' && ($legacyImage = $settings->get('seo', [])['image'] ?? null)) {
            $data['image'] = $legacyImage;
        }
        if ($section === 'mail') {
            $previous = $settings->get('mail', []);
            if (! empty($data['password'])) {
                $data['encrypted_password'] = Crypt::encryptString($data['password']);
            } elseif (empty($data['clear_password']) && isset($previous['encrypted_password'])) {
                $data['encrypted_password'] = $previous['encrypted_password'];
            }
            $data = Arr::except($data, ['password', 'clear_password']);
        }
        DB::transaction(function () use ($request, $settings, $section, $data): void {
            $settings->put($section, $data);
            Audit::record($request, 'settings.updated', $section);
        });

        return back()->with('status', 'Platform settings saved.');
    }

    public function member(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['verify', 'suspend', 'activate', 'promote', 'demote', 'delete'])]]);
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['action' => 'Manage your own account in personal settings.']);
        }
        DB::transaction(function () use ($request, $user, $data): void {
            $target = User::lockForUpdate()->findOrFail($user->id);
            $action = $data['action'];
            if ($target->role === 'admin' && in_array($action, ['suspend', 'demote', 'delete'], true)) {
                // Serialize administrator removals so concurrent requests cannot remove every administrator.
                DB::table('platform_settings')->insertOrIgnore(['key' => 'admin_role_lock', 'value' => 'true']);
                DB::table('platform_settings')->where('key', 'admin_role_lock')->lockForUpdate()->first();
                if (User::where('role', 'admin')->whereNull('suspended_at')->count() < 2 && ! $target->suspended_at) {
                    throw ValidationException::withMessages(['action' => 'Keep at least one active administrator.']);
                }
            }
            match ($action) {
                'verify' => $target->markEmailAsVerified(),
                'suspend' => $target->forceFill(['suspended_at' => now(), 'remember_token' => null])->save(),
                'activate' => $target->forceFill(['suspended_at' => null])->save(),
                'promote' => $target->hasVerifiedEmail() && ! $target->suspended_at ? $target->forceFill(['role' => 'admin'])->save() : throw ValidationException::withMessages(['action' => 'The member must be active and verified.']),
                'demote' => $target->forceFill(['role' => 'member'])->save(),
                'delete' => $this->deleteRequestedMember($target),
            };
            if (in_array($action, ['suspend', 'delete'], true) && config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $target->id)->delete();
            }
            Audit::record($request, "member.{$action}", "user:{$target->id}");
        }, attempts: 3);

        return back()->with('status', 'Member updated.');
    }

    public function navigation(Request $request, Settings $settings): RedirectResponse
    {
        $rules = [
            'announcement' => ['nullable', 'string', 'max:200'],
            'header' => ['present', 'array', 'max:8'],
            'footer' => ['present', 'array', 'max:16'],
        ];
        foreach (['header', 'footer'] as $area) {
            $rules["$area.*"] = ['array:label,href'];
            $rules["$area.*.label"] = ['required', 'string', 'max:55'];
            $rules["$area.*.href"] = ['required', 'string', 'max:255', function ($field, $value, $fail): void {
                $public = ['/', ...array_map(fn (string $slug) => '/'.$slug, PublicSiteController::BUILT_IN)];
                if (preg_match('~^/pages/('.PublicSiteController::SLUG_PATTERN.')$~D', $value, $matches) && DB::table('content_pages')->where('slug', $matches[1])->where('published', true)->exists()) {
                    return;
                }
                if (! in_array($value, $public, true) && (! str_starts_with($value, 'https://') || ! filter_var($value, FILTER_VALIDATE_URL))) {
                    $fail('Choose a public page path or a valid HTTPS URL.');
                }
            }];
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($request, $settings, $data): void {
            $settings->put('navigation', $data);
            Audit::record($request, 'navigation.updated', 'public website');
        });

        return back()->with('status', 'Website navigation saved.');
    }

    public function content(Request $request, ?string $slug = null): RedirectResponse
    {
        $creating = $slug === null;
        if (! $creating) {
            DB::table('content_pages')->where('slug', $slug)->firstOrFail();
        }
        $rules = ['title' => ['required', 'string', 'max:160'], 'meta_description' => ['nullable', 'string', 'max:200'], 'published' => ['required', 'boolean'], 'content' => ['required', 'array']];
        if ($creating) {
            $rules['slug'] = ['required', 'string', 'max:70', 'regex:/^'.PublicSiteController::SLUG_PATTERN.'$/D', Rule::notIn(['home', ...PublicSiteController::BUILT_IN]), Rule::unique('content_pages', 'slug')];
        }
        $rules += $slug === 'home' ? [
            'content.eyebrow' => ['required', 'string', 'max:100'], 'content.intro' => ['required', 'string', 'max:500'],
            'content.features' => ['required', 'array', 'max:12'], 'content.features.*.title' => ['required', 'string', 'max:100'],
            'content.features.*.body' => ['required', 'string', 'max:500'], 'content.steps' => ['required', 'array', 'max:12'], 'content.steps.*' => ['required', 'string', 'max:250'],
        ] : [
            'content.sections' => ['required', 'array', 'max:30'], 'content.sections.*.title' => ['required', 'string', 'max:160'],
            'content.sections.*.body' => ['required', 'string', 'max:15000'],
        ];
        $data = $request->validate($rules);
        $content = $slug === 'home' ? Arr::only($data['content'], ['eyebrow', 'intro', 'features', 'steps']) : Arr::only($data['content'], ['sections']);
        foreach (['features', 'sections'] as $collection) {
            if (isset($content[$collection])) {
                $content[$collection] = array_values(array_map(fn (array $item) => Arr::only($item, ['title', 'body']), $content[$collection]));
            }
        }
        if (isset($content['steps'])) {
            $content['steps'] = array_values($content['steps']);
        }
        $data['content'] = json_encode($content, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($request, $slug, $creating, $data): void {
            $pages = DB::table('content_pages');
            if ($creating) {
                $pages->insert($data + ['created_at' => now(), 'updated_at' => now()]);
                Audit::record($request, 'content.created', $data['slug']);
            } else {
                $pages->where('slug', $slug)->update($data + ['updated_at' => now()]);
                Audit::record($request, 'content.updated', $slug);
            }
        });

        return back()->with('status', 'Page saved.');
    }

    public function currency(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'regex:/^[A-Z]{3}$/D'], 'name' => ['required', 'string', 'max:100'], 'decimal_places' => ['required', 'integer', 'between:0,4'], 'enabled' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $data): void {
            $currency = DB::table('currencies')->where('code', $data['code'])->lockForUpdate()->first();
            if ($currency && (int) $currency->decimal_places !== (int) $data['decimal_places']) {
                throw ValidationException::withMessages(['decimal_places' => 'Keep the precision of an existing currency unchanged.']);
            }
            DB::table('currencies')->upsert([$data], ['code'], ['name', 'enabled']);
            Audit::record($request, 'currency.updated', $data['code']);
        });

        return back()->with('status', 'Currency saved.');
    }

    public function rate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_currency' => ['required', 'string', Rule::exists('currencies', 'code')], 'to_currency' => ['required', 'different:from_currency', 'string', Rule::exists('currencies', 'code')],
            'rate' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,12})?$/D'], 'quoted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'source' => ['required', 'string', 'max:120'],
        ]);
        if (bccomp($data['rate'], '0', 12) <= 0) {
            throw ValidationException::withMessages(['rate' => 'Enter a positive exchange rate.']);
        }
        DB::transaction(function () use ($request, $data): void {
            DB::table('exchange_rates')->upsert([$data + ['created_at' => now(), 'updated_at' => now()]], ['from_currency', 'to_currency', 'quoted_on', 'source'], ['rate', 'updated_at']);
            Audit::record($request, 'exchange_rate.updated', $data['from_currency'].'/'.$data['to_currency']);
        });

        return back()->with('status', 'Exchange rate saved.');
    }

    public function exchange(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['manual', 'open_exchange_rates'])],
            'credential' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{32}$/D'],
        ]);
        $existing = $settings->get('exchange', []);
        $secret = empty($data['credential']) ? ($existing['credential'] ?? null) : Crypt::encryptString($data['credential']);
        if ($data['provider'] === 'open_exchange_rates' && ! $secret) {
            throw ValidationException::withMessages(['credential' => 'Enter the Open Exchange Rates app ID.']);
        }
        DB::transaction(function () use ($request, $settings, $data, $secret): void {
            $settings->put('exchange', ['provider' => $data['provider'], 'credential' => $data['provider'] === 'manual' ? null : $secret]);
            Audit::record($request, 'exchange_provider.updated', $data['provider']);
        });

        return back()->with('status', 'Exchange-rate provider saved.');
    }

    public function refreshRates(Request $request, Settings $settings, RateSync $rates): RedirectResponse
    {
        try {
            $count = $rates->refresh($settings);
        } catch (Throwable) {
            throw ValidationException::withMessages(['provider' => 'The provider did not return usable quotes.']);
        }
        if ($count === null) {
            throw ValidationException::withMessages(['provider' => 'Configure an API provider before refreshing quotes.']);
        }
        Audit::record($request, 'exchange_rates.refreshed', 'Open Exchange Rates');

        return back()->with('status', "Saved {$count} exchange-rate pairs.");
    }

    public function template(Request $request, string $key): RedirectResponse
    {
        abort_unless(in_array($key, ['verify', 'reset', 'budget', 'recurring', 'goal', 'security'], true), 404);
        $data = $request->validate(['subject' => ['required', 'string', 'max:180', 'regex:/^[^\r\n]+$/D'], 'body' => ['required', 'string', 'max:5000'], 'enabled' => ['required', 'boolean']]);
        if (in_array($key, ['verify', 'reset'], true)) {
            $data['enabled'] = true;
        }
        DB::transaction(function () use ($request, $key, $data): void {
            DB::table('email_templates')->upsert([$data + ['key' => $key, 'created_at' => now(), 'updated_at' => now()]], ['key'], ['subject', 'body', 'enabled', 'updated_at']);
            Audit::record($request, 'email.template_updated', $key);
        });

        return back()->with('status', 'Email template saved.');
    }

    public function testMail(Request $request, Settings $settings, PlatformMail $mail): RedirectResponse
    {
        $user = $request->user();
        $status = 'sent';
        try {
            $mail->configure($settings)->raw('This is a Spendwise mail configuration test.', fn ($message) => $message->to($user->email)->subject('Spendwise mail test'));
        } catch (Throwable) {
            $status = 'failed';
        }
        DB::table('email_logs')->insert(['user_id' => $user->id, 'template' => 'test', 'status' => $status, 'created_at' => now()]);
        Audit::record($request, 'email.test', $status);
        if ($status === 'failed') {
            throw ValidationException::withMessages(['mail' => 'The configured mail transport rejected the test. Check its settings.']);
        }

        return back()->with('status', 'Test message processed by the configured transport.');
    }

    public function retryMail(Request $request, int $id): RedirectResponse
    {
        $row = DB::table('email_logs')->where(['id' => $id, 'status' => 'failed'])->whereNotNull('alert_id')->firstOrFail();
        SendFinanceAlert::dispatch($row->alert_id);
        Audit::record($request, 'email.retry', "email:{$id}");

        return back()->with('status', 'Email retry queued.');
    }

    private function deleteRequestedMember(User $user): void
    {
        if (! $user->deletion_requested_at) {
            throw ValidationException::withMessages(['action' => 'The member must request deletion first.']);
        }
        $receipts = DB::table('transaction_attachments')->whereIn('transaction_id', DB::table('transactions')->where('user_id', $user->id)->select('id'))->pluck('path')->all();
        if ($receipts) {
            DB::afterCommit(fn () => Storage::disk('local')->delete($receipts));
        }
        // Remove dependent records before deleting accounts with restrictive foreign keys.
        foreach (['transaction_imports', 'recurring_rules', 'budgets', 'transactions'] as $table) {
            DB::table($table)->where('user_id', $user->id)->delete();
        }
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        $avatar = DB::table('user_preferences')->where('user_id', $user->id)->value('avatar_path');
        if ($avatar) {
            DB::afterCommit(fn () => Storage::disk('local')->delete($avatar));
        }
        $user->delete();
    }
}
