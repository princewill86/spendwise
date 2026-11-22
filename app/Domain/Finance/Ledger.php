<?php

namespace App\Domain\Finance;

use App\Models\Transaction;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Ledger
{
    public function write(int $userId, array $data, ?Transaction $entry = null): Transaction
    {
        return DB::transaction(function () use ($userId, $data, $entry): Transaction {
            $entry = $entry === null
                ? new Transaction(['user_id' => $userId])
                : Transaction::query()->where('user_id', $userId)->lockForUpdate()->findOrFail($entry->id);

            $accounts = DB::table('financial_accounts')
                ->where('user_id', $userId)
                ->whereNull('archived_at')
                ->whereIn('id', array_filter([$data['account_id'], $data['destination_id'] ?? null]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()->keyBy('id');

            $source = $accounts->get($data['account_id']);
            $destination = $accounts->get($data['destination_id'] ?? 0);
            if (! $source || ($data['type'] === 'transfer' && ! $destination)) {
                throw ValidationException::withMessages(['account_id' => 'Choose active accounts from your workspace.']);
            }

            $category = $data['category_id'] ?? null;
            if ($category && ! DB::table('categories')->where([
                'id' => $category, 'user_id' => $userId, 'type' => $data['type'],
            ])->whereNull('archived_at')->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['category_id' => 'Choose an active category for this transaction type.']);
            }

            $scales = DB::table('currencies')->whereIn('code', $accounts->pluck('currency'))->pluck('decimal_places', 'code');
            $amount = Money::validate($data['amount'], (int) $scales[$source->currency], 'amount', $data['type'] !== 'adjustment');
            if (bccomp($amount, '0', Money::SCALE) === 0) {
                throw ValidationException::withMessages(['amount' => 'Enter a nonzero amount.']);
            }

            $received = null;
            if ($destination) {
                $sameCurrency = $source->currency === $destination->currency;
                $received = Money::validate($data['destination_amount'] ?? ($sameCurrency ? $amount : ''), (int) $scales[$destination->currency], 'destination_amount', true);
                if ($sameCurrency && bccomp($amount, $received, Money::SCALE) !== 0) {
                    throw ValidationException::withMessages(['destination_amount' => 'Transfers in the same currency must use the same amount on both sides.']);
                }
            }

            $entry->fill(Arr::only($data, ['type', 'description', 'occurred_on', 'notes', 'payment_method']));
            $entry->category_id = $category;
            $entry->save();

            // Replacing the postings inside the same transaction keeps balance reads atomic.
            DB::table('ledger_postings')->where('transaction_id', $entry->id)->delete();
            $postings = [[
                'account_id' => $source->id,
                'currency' => $source->currency,
                'amount' => in_array($data['type'], ['expense', 'transfer'], true) ? bcsub('0', $amount, Money::SCALE) : $amount,
            ]];
            if ($destination) {
                $postings[] = ['account_id' => $destination->id, 'currency' => $destination->currency, 'amount' => $received];
            }
            DB::table('ledger_postings')->insert(array_map(
                fn (array $posting) => $posting + ['transaction_id' => $entry->id, 'user_id' => $userId],
                $postings,
            ));

            return $entry;
        }, attempts: 3);
    }

    public function accounts(int $userId): Collection
    {
        $movements = DB::table('ledger_postings as p')->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->where('p.user_id', $userId)->whereNull('t.deleted_at')->groupBy('p.account_id')
            ->selectRaw('p.account_id, sum(p.amount) as movement');

        return DB::table('financial_accounts as a')
            ->leftJoinSub($movements, 'm', 'a.id', '=', 'm.account_id')
            ->where('a.user_id', $userId)->orderBy('a.name')
            ->select('a.*')->selectRaw('a.opening_balance + coalesce(m.movement, 0) as balance')->get()
            ->map(function (object $account): object {
                $account->balance = bcadd((string) $account->balance, '0', Money::SCALE);

                return $account;
            });
    }
}
