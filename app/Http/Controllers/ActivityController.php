<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user()->id;
        $alerts = $request->routeIs('notifications');

        return Inertia::render('Activity', [
            'section' => $alerts ? 'notifications' : ($request->routeIs('calendar') ? 'calendar' : 'recurring'),
            'rules' => $alerts ? null : $this->rules($user)->when($request->routeIs('calendar'), fn (Builder $query) => $query->where('status', 'active'))->orderBy('next_on')->orderBy('id')->paginate(20),
            'alerts' => $alerts ? $this->alerts($user)->latest('id')->paginate(20) : null,
            'accounts' => $alerts ? [] : DB::table('financial_accounts')->where('user_id', $user)->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'currency']),
            'categories' => $alerts ? [] : DB::table('categories')->where('user_id', $user)->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'type']),
            'today' => today()->toDateString(),
        ]);
    }

    public function rule(Request $request, ?int $id = null): RedirectResponse
    {
        $user = $request->user()->id;
        $rules = $this->rules($user);
        $current = $id === null ? null : (clone $rules)->where('id', $id)->firstOrFail();
        $type = $request->input('type');
        $data = $request->validate([
            'description' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')->where('user_id', $user)->whereNull('archived_at')],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $user)->where('type', is_string($type) ? $type : '')->whereNull('archived_at')],
            'amount' => ['required', 'string', 'max:22'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'interval_unit' => ['required', Rule::in(['days', 'weeks', 'months'])],
            'interval_count' => ['required', 'integer', 'between:1,365'],
            'status' => ['required', Rule::in(['active', 'paused', 'cancelled'])],
        ]);
        $currencyScale = DB::table('financial_accounts as a')->join('currencies as c', 'c.code', '=', 'a.currency')
            ->where('a.user_id', $user)->where('a.id', $data['account_id'])->value('c.decimal_places');
        $data['amount'] = Money::validate($data['amount'], (int) $currencyScale, 'amount', true);
        $data['updated_at'] = now();
        $data['category_id'] ??= null;

        DB::transaction(function () use ($rules, $id, $current, $data, $user): void {
            if ($current) {
                $target = $rules->where('id', $id);
                $locked = (clone $target)->lockForUpdate()->firstOrFail();
                $next = $locked->starts_on === $data['starts_on'] ? $locked->next_on : max($locked->next_on, $data['starts_on']);
                $target->update($data + ['next_on' => $next]);
            } else {
                $rules->insert($data + ['user_id' => $user, 'next_on' => $data['starts_on'], 'created_at' => now()]);
            }
        });

        return back()->with('status', 'Recurring rule saved.');
    }

    public function notification(Request $request, ?int $id = null): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in($id === null ? ['read'] : ['read', 'dismiss'])]]);
        $query = $this->alerts($request->user()->id);
        if ($id !== null) {
            $query->where('id', $id)->firstOrFail();
        }

        $query->update([$data['action'] === 'read' ? 'read_at' : 'dismissed_at' => now(), 'updated_at' => now()]);

        return back();
    }

    private function rules(int $user): Builder
    {
        return DB::table('recurring_rules')->where('user_id', $user);
    }

    private function alerts(int $user): Builder
    {
        return DB::table('user_alerts')->where('user_id', $user)->whereNull('dismissed_at');
    }
}
