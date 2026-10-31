<?php

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLoanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('team_id', $this->user()?->current_team_id)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('team_id', $this->user()?->current_team_id)],
            'parts' => ['required', 'array', 'min:1', 'max:10'],
            'parts.*.label' => ['required', 'string', 'max:255'],
            'parts.*.principal' => ['required', 'integer', 'min:1'],
            'parts.*.rate_milli' => ['required', 'integer', 'min:0', 'max:30000'],
            'parts.*.months' => ['required', 'integer', 'min:1', 'max:600'],
            'parts.*.first_payment_date' => ['required', 'date'],
            'parts.*.payment' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('name'),
            'account_id' => __('account'),
            'category_id' => __('category'),
            'parts.*.label' => __('label'),
            'parts.*.principal' => __('amount borrowed'),
            'parts.*.rate_milli' => __('rate'),
            'parts.*.months' => __('term'),
            'parts.*.first_payment_date' => __('first payment'),
            'parts.*.payment' => __('monthly payment'),
        ];
    }
}
