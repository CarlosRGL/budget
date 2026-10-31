<?php

namespace App\Http\Requests\Accounts;

use App\Enums\AccountType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'on_budget' => ['required', 'boolean'],
            'balance' => ['required', 'integer', 'between:-100000000000,100000000000'],
            'starting_date' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('name'),
            'type' => __('type'),
            'on_budget' => __('budgeted'),
            'balance' => __('balance'),
            'starting_date' => __('date'),
        ];
    }
}
