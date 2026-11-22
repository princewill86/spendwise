<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('transaction');

        return $entry === null || $this->user()->can('update', $entry);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['income', 'expense', 'transfer', 'adjustment'])],
            'description' => ['required', 'string', 'max:160'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_method' => ['nullable', 'string', 'max:48'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'amount' => ['required', 'string', 'max:22'],
            'account_id' => ['required', 'integer', 'min:1'],
            'destination_id' => ['required_if:type,transfer', 'prohibited_unless:type,transfer', 'nullable', 'integer', 'different:account_id', 'min:1'],
            'destination_amount' => ['prohibited_unless:type,transfer', 'nullable', 'string', 'max:22'],
            'category_id' => ['nullable', 'integer', 'min:1', 'prohibited_if:type,transfer,adjustment'],
        ];
    }
}
