<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Alerts;
use App\Domain\Finance\Money;
use App\Domain\Finance\Plans;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlansController extends Controller
{
    public function index(Request $request, Plans $plans): Response
    {
        $user = $request->user()->id;
        $budgets = $request->routeIs('budgets');
        $goals = $this->owned('savings_goals', $user);
        $selected = $budgets || ! $request->filled('goal') ? null : (clone $goals)->where('id', $request->integer('goal'))->firstOrFail();

        return Inertia::render('Plans', [
            'section' => $budgets ? 'budgets' : 'goals',
            'currencies' => DB::table('currencies')->where('enabled', true)->orderBy('code')->pluck('code'),
            'categories' => DB::table('categories')->where(['user_id' => $user, 'type' => 'expense'])->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'budgets' => $budgets ? $this->owned('budgets', $user)->latest('starts_on')->latest('id')->paginate(15)->through($plans->budgetProgress(...)) : null,
            'goals' => $budgets ? null : $goals->latest('id')->paginate(15)->through($plans->goalProgress(...)),
            'selected' => $selected,
            'history' => $selected ? $plans->contributions($selected->id)->latest('id')->paginate(15, pageName: 'history')->withQueryString() : null,
            'today' => now()->toDateString(),
        ]);
    }

    public function budget(Request $request, ?int $id = null): RedirectResponse
    {
        $user = $request->user()->id;
        $query = $this->owned('budgets', $user);
        $current = $id === null ? null : (clone $query)->where('id', $id)->firstOrFail();
        $data = $request->validate($this->commonRules($current) + [
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn (Builder $q) => $q->where(['user_id' => $user, 'type' => 'expense'])->where(fn (Builder $q) => $q->whereNull('archived_at')->orWhere('id', $current?->category_id)))],
            'period' => ['required', Rule::in(['weekly', 'monthly', 'custom'])],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'threshold' => ['required', 'integer', 'between:1,100'],
            'archived' => ['required', 'boolean'],
        ]);
        $start = CarbonImmutable::parse($data['starts_on']);
        [$start, $end] = match ($data['period']) {
            'weekly' => [$start->startOfWeek()->toDateString(), $start->endOfWeek()->toDateString()],
            'monthly' => [$start->startOfMonth()->toDateString(), $start->endOfMonth()->toDateString()],
            default => [$start->toDateString(), $data['ends_on']],
        };
        $data['starts_on'] = $start;
        $data['ends_on'] = $end;
        $data['category_id'] ??= null;
        $data['archived_at'] = $data['archived'] ? ($current?->archived_at ?? now()) : null;
        $this->save($query, $user, $current, Arr::except($data, 'archived'));

        return back()->with('status', 'Budget saved.');
    }

    public function goal(Request $request, ?int $id = null): RedirectResponse
    {
        $user = $request->user()->id;
        $query = $this->owned('savings_goals', $user);
        $current = $id === null ? null : (clone $query)->where('id', $id)->firstOrFail();
        $data = $request->validate($this->commonRules($current) + [
            'target_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'complete', 'archived'])],
        ]);
        $this->save($query, $user, $current, $data);

        return back()->with('status', 'Savings goal saved.');
    }

    public function contribution(Request $request, Plans $plans, Alerts $alerts, int $id): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:22'],
            'direction' => ['required', Rule::in(['deposit', 'withdrawal'])],
            'note' => ['nullable', 'string', 'max:240'],
        ]);

        DB::transaction(function () use ($request, $plans, $alerts, $id, $data): void {
            // Lock the goal so two withdrawals cannot spend the same tracked funds.
            $goal = $this->owned('savings_goals', $request->user()->id)->lockForUpdate()->where('id', $id)->firstOrFail();
            if ($goal->status !== 'active') {
                throw ValidationException::withMessages(['amount' => 'Reopen the goal to record a contribution or withdrawal.']);
            }
            $amount = $this->validateAmount($data['amount'], $goal->currency);
            if ($data['direction'] === 'withdrawal') {
                if (bccomp($amount, $plans->saved($goal->id), Money::SCALE) > 0) {
                    throw ValidationException::withMessages(['amount' => 'The withdrawal exceeds the tracked savings.']);
                }
                $amount = bcsub('0', $amount, Money::SCALE);
            }
            $before = $plans->saved($goal->id);
            DB::table('savings_contributions')->insert([
                'savings_goal_id' => $goal->id, 'amount' => $amount, 'note' => $data['note'] ?? null, 'created_at' => now(),
            ]);
            if (bccomp($before, (string) $goal->amount, Money::SCALE) < 0 && bccomp(bcadd($before, $amount, Money::SCALE), (string) $goal->amount, Money::SCALE) >= 0) {
                $alerts->send($goal->user_id, "goal:{$goal->id}:target", "You reached the savings target for {$goal->name}.");
            }
        }, attempts: 3);

        return back()->with('status', 'Savings activity recorded.');
    }

    private function commonRules(?object $current): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'currency' => $current ? ['missing'] : ['required', Rule::exists('currencies', 'code')->where('enabled', true)],
            'amount' => ['required', 'string', 'max:22'],
        ];
    }

    private function save(Builder $query, int $user, ?object $current, array $data): void
    {
        $data['amount'] = $this->validateAmount($data['amount'], $current?->currency ?? $data['currency']);
        $data['updated_at'] = now();
        if ($current) {
            $query->where('id', $current->id)->update($data);
        } else {
            $query->insert($data + ['user_id' => $user, 'created_at' => now()]);
        }
    }

    private function validateAmount(string $amount, string $currency): string
    {
        return Money::validate($amount, (int) DB::table('currencies')->where('code', $currency)->value('decimal_places'), 'amount', true);
    }

    private function owned(string $table, int $user): Builder
    {
        return DB::table($table)->where('user_id', $user);
    }
}
