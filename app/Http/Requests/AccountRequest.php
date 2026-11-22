<?php

namespace App\Http\Requests;

use App\Domain\Platform\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account === null || $this->user()->can('update', $account);
    }

    public function rules(): array
    {
        $settings = app(Settings::class);
        $currency = ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('enabled', true)];
        if (! $settings->enabled('multi_currency')) {
            $currency[] = Rule::in([$settings->get('general', [])['currency'] ?? 'USD']);
        }

        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['cash', 'bank', 'savings', 'wallet', 'credit', 'other'])],
            'currency' => $this->route('account') ? ['missing'] : $currency,
            'opening_balance' => ['required', 'string', 'max:22'],
            'description' => ['nullable', 'string', 'max:2000'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }
}
