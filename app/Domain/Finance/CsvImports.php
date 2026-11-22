<?php

namespace App\Domain\Finance;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CsvImports
{
    public function preview(string $file, array $mapping, object $account, ?int $category, int $limit = 500): array
    {
        $stream = fopen($file, 'rb');
        try {
            $header = fgetcsv($stream, escape: '');
            if (! $header) {
                throw ValidationException::withMessages(['file' => 'The CSV must include a header row.']);
            }
            $header = array_map(fn ($value) => trim((string) $value), $header);
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $fields = [];
            foreach (['date', 'description', 'amount'] as $field) {
                $matches = array_keys($header, $mapping[$field.'_column'], true);
                if (count($matches) !== 1) {
                    throw ValidationException::withMessages([$field.'_column' => 'Choose an unambiguous CSV column name.']);
                }
                $fields[$field] = $matches[0];
            }
            $rows = [];
            $seen = [];
            while (($cells = fgetcsv($stream, escape: '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                if (count($rows) >= $limit) {
                    throw ValidationException::withMessages(['file' => "Import up to {$limit} rows per file."]);
                }
                $line = count($rows) + 2;
                try {
                    $rows[] = $this->row($cells, $fields, $mapping, $account, $category, $line, $seen);
                } catch (ValidationException $error) {
                    $rows[] = ['line' => $line, 'error' => $error->getMessage()];
                }
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function row(array $cells, array $fields, array $mapping, object $account, ?int $category, int $line, array &$seen): array
    {
        $rawDate = trim($cells[$fields['date']] ?? '');
        $description = trim($cells[$fields['description']] ?? '');
        $value = trim($cells[$fields['amount']] ?? '');
        if (str_contains($rawDate.$description.$value, "\0")) {
            throw ValidationException::withMessages(['file' => 'The row contains invalid text.']);
        }
        $date = DateTimeImmutable::createFromFormat('!'.$mapping['date_format'], $rawDate);
        if (! $date || $date->format($mapping['date_format']) !== $rawDate || $date->format('Y-m-d') > today()->toDateString()
            || $description === '' || ! mb_check_encoding($description) || mb_strlen($description) > 160) {
            throw ValidationException::withMessages(['file' => 'Check that the date and description are valid.']);
        }
        $amount = Money::validate($value, (int) $account->decimal_places, 'amount');
        $sign = bccomp($amount, '0', Money::SCALE);
        if ($sign === 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be nonzero.']);
        }

        $type = $sign > 0 ? 'income' : 'expense';
        $normalized = [
            'account_id' => $account->id, 'occurred_on' => $date->format('Y-m-d'), 'description' => $description,
            'type' => $type, 'amount' => $sign > 0 ? $amount : bcsub('0', $amount, Money::SCALE),
            'category_id' => $type === 'expense' ? $category : null,
        ];
        $key = json_encode([$normalized['occurred_on'], $description, $amount], JSON_THROW_ON_ERROR);
        $duplicate = isset($seen[$key]) || $this->exists($account->user_id, $normalized);
        $seen[$key] = true;

        return ['line' => $line, 'entry' => $normalized, 'error' => $duplicate ? 'Duplicate transaction.' : null];
    }

    public function exists(int $user, array $row): bool
    {
        $signed = $row['type'] === 'expense' ? bcsub('0', $row['amount'], Money::SCALE) : $row['amount'];

        return DB::table('ledger_postings as p')->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->where(['p.user_id' => $user, 'p.account_id' => $row['account_id'], 'p.amount' => $signed,
                't.type' => $row['type'], 't.occurred_on' => $row['occurred_on'], 't.description' => $row['description']])
            ->whereNull('t.deleted_at')->exists();
    }
}
