<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Conversion;
use App\Domain\Finance\Ledger;
use App\Domain\Finance\Money;
use App\Domain\Finance\Overview;
use App\Domain\Finance\Plans;
use App\Domain\Platform\Settings;
use App\Http\Requests\AccountRequest;
use App\Http\Requests\TransactionRequest;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function index(Request $request, Ledger $ledger, Overview $overview, Conversion $conversion, Settings $settings): Response
    {
        $userId = $request->user()->id;
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['income', 'expense', 'transfer', 'adjustment'])],
            'category' => ['nullable', 'integer', 'min:1'],
            'account' => ['nullable', 'integer', 'min:1'],
            'period' => ['nullable', Rule::in(['week', 'month', 'previous', 'year', 'custom'])],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'order' => ['nullable', Rule::in(['recent', 'oldest'])],
        ]);
        $entries = DB::table('transactions as t')->leftJoin('categories as c', 'c.id', '=', 't.category_id')->leftJoin('transaction_attachments as f', 'f.transaction_id', '=', 't.id')
            ->where('t.user_id', $userId)->whereNull('t.deleted_at')
            ->select('t.id', 't.type', 't.description', 't.occurred_on', 't.notes', 't.payment_method', 't.category_id', 'c.name as category', 'f.filename as attachment');

        if (! empty($filters['q'])) {
            $entries->whereLike('t.description', '%'.$filters['q'].'%');
        }
        if (! empty($filters['type'])) {
            $entries->where('t.type', $filters['type']);
        }
        if (! empty($filters['category'])) {
            $entries->where('t.category_id', $filters['category']);
        }
        if (! empty($filters['account'])) {
            $entries->whereExists(fn ($query) => $query->selectRaw('1')->from('ledger_postings as p')->whereColumn('p.transaction_id', 't.id')->where('p.account_id', $filters['account']));
        }
        if (! empty($filters['from'])) {
            $entries->where('t.occurred_on', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $entries->where('t.occurred_on', '<=', $filters['to']);
        }

        $direction = ($filters['order'] ?? '') === 'oldest' ? 'asc' : 'desc';
        $page = $entries->orderBy('t.occurred_on', $direction)->orderBy('t.id', $direction)->paginate(20)->withQueryString();
        $postings = DB::table('ledger_postings')->join('financial_accounts', 'financial_accounts.id', '=', 'ledger_postings.account_id')
            ->where('ledger_postings.user_id', $userId)->whereIn('transaction_id', $page->pluck('id'))
            ->orderBy('ledger_postings.id')
            ->get(['transaction_id', 'account_id', 'ledger_postings.amount', 'ledger_postings.currency', 'financial_accounts.name'])
            ->groupBy('transaction_id');

        $accounts = $ledger->accounts($userId);
        $preferences = DB::table('user_preferences')->where('user_id', $userId)->first();
        $general = $settings->get('general', []);
        $display = $preferences?->currency ?? $general['currency'] ?? null;
        $isOverview = $request->routeIs('dashboard');
        $summary = $isOverview ? $overview->summarize($userId, $accounts, $filters + ['period' => $preferences?->reporting_period ?? 'month']) : [];
        $estimate = $summary && $display && $settings->enabled('conversion', false)
            ? $conversion->balances($summary, $display, today()->toDateString()) : null;

        return Inertia::render('Dashboard', [
            'name' => $request->user()->name,
            'section' => $request->routeIs('dashboard') ? 'overview' : $request->route()->getName(),
            'accounts' => $accounts,
            'summary' => $summary,
            'monthlyTrend' => $isOverview ? $overview->monthlyTrend($userId) : [],
            'estimate' => $estimate,
            'displayCurrency' => $display,
            'defaultAccount' => $accounts->first(fn (object $account) => ! $account->archived_at && (int) $account->id === (int) $preferences?->default_account_id)?->id,
            'preferences' => $preferences ? ['hide_balances' => $preferences->hide_balances, 'reporting_period' => $preferences->reporting_period, 'date_format' => $preferences->date_format, 'number_format' => $preferences->number_format] : null,
            'budgetHighlights' => $isOverview ? DB::table('budgets')->where('user_id', $userId)->whereNull('archived_at')->where('starts_on', '<=', today()->toDateString())->where('ends_on', '>=', today()->toDateString())->limit(4)->get()->map(app(Plans::class)->budgetProgress(...)) : [],
            'goalHighlights' => $isOverview && $settings->enabled('goals') ? DB::table('savings_goals')->where(['user_id' => $userId, 'status' => 'active'])->limit(4)->get()->map(app(Plans::class)->goalProgress(...)) : [],
            'upcoming' => $isOverview && $settings->enabled('recurring') ? DB::table('recurring_rules')->where(['user_id' => $userId, 'status' => 'active'])->orderBy('next_on')->limit(4)->get(['id', 'description', 'next_on']) : [],
            'categories' => DB::table('categories')->where('user_id', $userId)->orderBy('name')->get(),
            'currencies' => DB::table('currencies')->where('enabled', true)->orderBy('code')->get(),
            'today' => now()->toDateString(),
            'filters' => $filters,
            'entries' => $page->through(function (object $entry) use ($postings): object {
                $entry->postings = $postings->get($entry->id, collect());

                return $entry;
            }),
        ]);
    }

    public function account(AccountRequest $request, ?FinancialAccount $account = null): RedirectResponse
    {
        $data = $request->validated();
        $code = $account?->currency ?? $data['currency'];
        $scale = DB::table('currencies')->where('code', $code)->value('decimal_places');
        $data['opening_balance'] = Money::validate($data['opening_balance'], (int) $scale, 'opening_balance');
        $account ??= new FinancialAccount(['user_id' => $request->user()->id, 'currency' => $code]);
        $account->fill(Arr::only($data, ['name', 'type', 'opening_balance', 'description']));
        if (array_key_exists('archived', $data)) {
            $account->archived_at = $data['archived'] ? ($account->archived_at ?? now()) : null;
        }
        $account->save();

        return back()->with('status', 'Account saved.');
    }

    public function reconcile(Request $request, Ledger $ledger, FinancialAccount $account): RedirectResponse
    {
        Gate::authorize('update', $account);
        $data = $request->validate(['balance' => ['required', 'string', 'max:22']]);

        DB::transaction(function () use ($ledger, $account, $data): void {
            $locked = FinancialAccount::query()->whereNull('archived_at')->lockForUpdate()->findOrFail($account->id);
            $scale = DB::table('currencies')->where('code', $locked->currency)->value('decimal_places');
            $actual = Money::validate($data['balance'], (int) $scale, 'balance');
            $current = $ledger->accounts($locked->user_id)->firstWhere('id', $locked->id)->balance;
            $difference = bcsub($actual, $current, Money::SCALE);
            if (bccomp($difference, '0', Money::SCALE) !== 0) {
                $ledger->write($locked->user_id, [
                    'account_id' => $locked->id, 'type' => 'adjustment', 'amount' => $difference,
                    'description' => 'Balance reconciliation', 'occurred_on' => today()->toDateString(),
                ]);
            }
        }, attempts: 3);

        return back()->with('status', 'Account reconciled.');
    }

    public function transaction(TransactionRequest $request, Ledger $ledger, ?Transaction $transaction = null): RedirectResponse
    {
        $ledger->write($request->user()->id, $request->validated(), $transaction);

        return back()->with('status', 'Transaction saved.');
    }

    public function delete(Request $request, Transaction $transaction): RedirectResponse
    {
        Gate::authorize('update', $transaction);
        $request->validate(['confirm' => ['accepted']]);
        $transaction->delete();

        return back()->with('status', 'Transaction deleted.');
    }

    public function category(Request $request, ?int $category = null): RedirectResponse
    {
        $userId = $request->user()->id;
        $categories = DB::table('categories')->where('user_id', $userId);
        $existing = $category === null ? null : (clone $categories)->where('id', $category)->first();
        abort_if($category !== null && $existing === null, 404);
        $type = $existing?->type ?? $request->input('type');
        $unique = Rule::unique('categories', 'name')->where('user_id', $userId)->where('type', is_string($type) ? $type : '');
        if ($existing) {
            $unique->ignore($existing->id);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', $unique],
            'type' => $existing ? ['missing'] : ['required', Rule::in(['income', 'expense'])],
            'color' => ['nullable', 'regex:/^#[0-9a-f]{6}$/iD'],
            'icon' => ['nullable', 'regex:/^[a-z0-9-]{1,32}$/D'],
            'archived' => ['sometimes', 'boolean'],
        ]);
        $updates = Arr::except($data, ['archived']) + ['updated_at' => now()];
        if (array_key_exists('archived', $data)) {
            $updates['archived_at'] = $data['archived'] ? ($existing?->archived_at ?? now()) : null;
        }
        if ($existing) {
            $categories->where('id', $category)->update($updates);
        } else {
            $categories->insert($updates + ['user_id' => $userId, 'created_at' => now()]);
        }

        return back()->with('status', 'Category saved.');
    }
}
