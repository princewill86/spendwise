<?php

namespace App\Http\Controllers;

use App\Domain\Finance\CsvImports;
use App\Domain\Finance\Ledger;
use App\Domain\Finance\Plans;
use App\Domain\Platform\Settings;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataController extends Controller
{
    public function imports(Request $request): Response
    {
        $user = $request->user()->id;
        $draft = $request->filled('draft')
            ? DB::table('transaction_imports')->where(['id' => $request->integer('draft'), 'user_id' => $user])->firstOrFail()
            : null;

        return Inertia::render('Data', [
            'section' => 'imports',
            'accounts' => $this->accounts($user)->get(['a.id', 'a.currency', 'a.name']),
            'categories' => DB::table('categories')->where(['user_id' => $user, 'type' => 'expense'])->whereNull('archived_at')->get(['id', 'name']),
            'draft' => $draft ? ['id' => $draft->id, 'rows' => json_decode($draft->rows ?? '[]', true, flags: JSON_THROW_ON_ERROR), 'accepted_count' => $draft->accepted_count, 'rejected_count' => $draft->rejected_count, 'confirmed_at' => $draft->confirmed_at] : null,
            'imports' => DB::table('transaction_imports')->where('user_id', $user)->latest('id')->paginate(15, ['id', 'filename', 'accepted_count', 'rejected_count', 'confirmed_at', 'created_at']),
        ]);
    }

    public function preview(Request $request, CsvImports $imports, Settings $settings): RedirectResponse
    {
        $user = $request->user()->id;
        $data = $request->validate([
            'file' => ['required', 'file', 'max:1024', 'mimetypes:text/plain,text/csv,application/csv,text/x-csv,application/vnd.ms-excel'],
            'account_id' => ['required', 'integer'],
            'date_format' => ['required', Rule::in(['Y-m-d', 'd/m/Y', 'm/d/Y'])],
            'date_column' => ['required', 'string', 'max:100', 'different:amount_column,description_column'],
            'amount_column' => ['required', 'string', 'max:100', 'different:description_column'],
            'description_column' => ['required', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $user)->where('type', 'expense')->whereNull('archived_at')],
        ]);
        $account = $this->accounts($user)->where('a.id', $data['account_id'])->first();
        if (! $account) {
            throw ValidationException::withMessages(['account_id' => 'Choose an active account in your workspace.']);
        }
        $rows = $imports->preview($request->file('file')->getPathname(), $data, $account, $data['category_id'] ?? null, (int) ($settings->get('finance', [])['import_rows'] ?? 500));
        $accepted = count(array_filter($rows, fn (array $row): bool => $row['error'] === null));
        $id = DB::table('transaction_imports')->insertGetId([
            'user_id' => $user, 'account_id' => $account->id,
            'filename' => mb_substr(mb_scrub($request->file('file')->getClientOriginalName()), 0, 200),
            'rows' => json_encode($rows, JSON_THROW_ON_ERROR), 'accepted_count' => $accepted, 'rejected_count' => count($rows) - $accepted,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('imports', ['draft' => $id]);
    }

    public function confirm(Request $request, CsvImports $imports, Ledger $ledger, int $id): RedirectResponse
    {
        $user = $request->user()->id;
        $request->validate(['confirm' => ['accepted']]);

        DB::transaction(function () use ($imports, $ledger, $id, $user): void {
            $query = DB::table('transaction_imports')->where(['id' => $id, 'user_id' => $user]);
            $draft = (clone $query)->lockForUpdate()->firstOrFail();
            if ($draft->confirmed_at) {
                return;
            }
            DB::table('financial_accounts')->where(['user_id' => $user, 'id' => $draft->account_id])->whereNull('archived_at')->lockForUpdate()->firstOrFail();
            if (! $draft->accepted_count) {
                throw ValidationException::withMessages(['confirm' => 'This preview has no valid rows.']);
            }
            $count = 0;
            foreach (json_decode($draft->rows, true, flags: JSON_THROW_ON_ERROR) as $row) {
                if ($row['error'] === null && ! $imports->exists($user, $row['entry'])) {
                    $ledger->write($user, $row['entry']);
                    $count++;
                }
            }
            $query->update([
                'rows' => null, 'accepted_count' => $count,
                'rejected_count' => $draft->accepted_count + $draft->rejected_count - $count,
                'confirmed_at' => now(), 'updated_at' => now(),
            ]);
        }, attempts: 3);

        return redirect()->route('imports')->with('status', 'Import processed. Review the recorded counts in your import history.');
    }

    public function reports(Request $request, Ledger $ledger, Plans $plans, Settings $settings): Response
    {
        $user = $request->user()->id;
        $filters = $this->filters($request);
        $entries = $this->postings($user, $filters);

        return Inertia::render('Data', [
            'section' => 'reports',
            'printable' => $settings->enabled('pdf_exports'),
            'filters' => $filters,
            'accounts' => $ledger->accounts($user),
            'categories' => DB::table('categories')->where('user_id', $user)->orderBy('name')->get(['id', 'name']),
            'currencies' => DB::table('financial_accounts')->where('user_id', $user)->distinct()->orderBy('currency')->pluck('currency'),
            'totals' => (clone $entries)->selectRaw('p.currency, t.type, sum(p.amount) as total')->groupBy('p.currency', 't.type')->orderBy('p.currency')->orderBy('t.type')->get(),
            'cashFlow' => (clone $entries)->whereIn('t.type', ['income', 'expense'])->groupBy('p.currency')->orderBy('p.currency')->selectRaw('p.currency, sum(p.amount) as total')->get(),
            'spending' => (clone $entries)->where('t.type', 'expense')->groupBy('p.currency', 't.category_id', 'c.name')->orderBy('p.currency')->orderByRaw('sum(p.amount) asc')
                ->selectRaw("p.currency, coalesce(c.name, 'Uncategorized') as name, -sum(p.amount) as total")->get(),
            'months' => (clone $entries)->whereIn('t.type', ['income', 'expense'])->selectRaw('substring(cast(t.occurred_on as varchar), 1, 7) as month, p.currency, t.type, sum(p.amount) as total')
                ->groupByRaw('substring(cast(t.occurred_on as varchar), 1, 7), p.currency, t.type')->orderBy('month')->get(),
            'budgets' => DB::table('budgets')->where('user_id', $user)->whereNull('archived_at')->latest('starts_on')->limit(10)->get()->map($plans->budgetProgress(...)),
            'goals' => $settings->enabled('goals') ? DB::table('savings_goals')->where('user_id', $user)->where('status', 'active')->latest('id')->limit(10)->get()->map($plans->goalProgress(...)) : [],
            'recurring' => $settings->enabled('recurring') ? DB::table('recurring_rules')->where(['user_id' => $user, 'status' => 'active', 'type' => 'expense'])->orderBy('next_on')->limit(10)->get(['description', 'amount', 'next_on']) : [],
        ]);
    }

    public function printable(Request $request): \Illuminate\Http\Response
    {
        $entries = $this->postings($request->user()->id, $this->filters($request));
        $rows = $entries->orderByDesc('t.occurred_on')->orderBy('p.id')->limit(1001)->get(['t.occurred_on', 't.description', 't.type', 'a.name as account', 'p.currency', 'p.amount']);
        if ($rows->count() > 1000) {
            throw ValidationException::withMessages(['from' => 'Choose a narrower date range to print up to 1,000 postings.']);
        }

        return response()->view('print-report', ['rows' => $rows]);
    }

    public function export(Request $request, Ledger $ledger, Plans $plans, Settings $settings, string $kind): StreamedResponse
    {
        $user = $request->user()->id;
        abort_if($kind === 'reports' && ! $settings->enabled('reports'), 404);
        $filters = $this->filters($request);

        return match ($kind) {
            'transactions', 'reports' => $this->csv($kind, ['date', 'description', 'type', 'category', 'account', 'currency', 'amount', 'payment method'], function () use ($user, $filters) {
                foreach ($this->postings($user, $filters)->select('p.id', 't.occurred_on', 't.description', 't.type', 'c.name as category', 'a.name as account', 'p.currency', 'p.amount', 't.payment_method')->lazyById(500, 'p.id', 'id') as $row) {
                    yield [$row->occurred_on, $row->description, $row->type, $row->category, $row->account, $row->currency, $row->amount, $row->payment_method];
                }
            }),
            'accounts' => $this->csv($kind, ['name', 'type', 'currency', 'opening balance', 'current balance', 'archived'], fn () => $ledger->accounts($user)->map(fn ($row) => [$row->name, $row->type, $row->currency, $row->opening_balance, $row->balance, $row->archived_at])),
            'budgets' => $this->csv($kind, ['name', 'currency', 'start', 'end', 'limit', 'spent', 'remaining'], fn () => DB::table('budgets')->where('user_id', $user)->lazyById()->map(function ($row) use ($plans) {
                $row = $plans->budgetProgress($row);

                return [$row->name, $row->currency, $row->starts_on, $row->ends_on, $row->amount, $row->spent, $row->remaining];
            })),
        };
    }

    private function csv(string $title, array $headers, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, $headers, escape: '');
            foreach ($rows() as $row) {
                // Avoid executing user-entered text as spreadsheet formulas.
                $escaped = array_map(fn ($value) => is_string($value) && preg_match('/^[\s]*[=+@-]/u', $value) && ! preg_match('/^-\d+(?:\.\d+)?$/D', $value) ? "'".$value : $value, $row);
                fputcsv($stream, $escaped, escape: '');
            }
            fclose($stream);
        }, "spendwise-{$title}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:120'],
            'account' => ['nullable', 'integer'],
            'category' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(['income', 'expense', 'transfer', 'adjustment'])],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
    }

    private function postings(int $user, array $filters): Builder
    {
        $query = DB::table('ledger_postings as p')->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->join('financial_accounts as a', 'a.id', '=', 'p.account_id')->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->where('p.user_id', $user)->whereNull('t.deleted_at');

        if (! empty($filters['q'])) {
            $query->whereLike('t.description', '%'.$filters['q'].'%');
        }
        foreach (['account' => 'p.account_id', 'category' => 't.category_id', 'type' => 't.type', 'currency' => 'p.currency'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('t.occurred_on', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('t.occurred_on', '<=', $filters['to']);
        }

        return $query;
    }

    private function accounts(int $user): Builder
    {
        return DB::table('financial_accounts as a')->join('currencies as c', 'a.currency', '=', 'c.code')->where('a.user_id', $user)->whereNull('a.archived_at');
    }
}
