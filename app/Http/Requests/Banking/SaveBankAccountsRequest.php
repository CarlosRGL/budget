<?php

namespace App\Http\Requests\Banking;

use App\Enums\AccountType;
use App\Models\BankConnection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBankAccountsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var BankConnection $connection */
        $connection = $this->route('bank_connection');

        return [
            'mappings' => ['required', 'array'],
            'mappings.*.bank_account_id' => ['required', 'integer', 'distinct', Rule::exists('bank_accounts', 'id')->where('bank_connection_id', $connection->id)],
            'mappings.*.action' => ['required', Rule::in(['existing', 'new', 'ignore'])],
            'mappings.*.account_id' => [
                'nullable', 'required_if:mappings.*.action,existing', 'integer', 'distinct',
                Rule::exists('accounts', 'id')->where('team_id', $this->user()?->current_team_id),
            ],
            'mappings.*.name' => ['nullable', 'required_if:mappings.*.action,new', 'string', 'max:255'],
            'mappings.*.type' => ['nullable', 'required_if:mappings.*.action,new', Rule::enum(AccountType::class)],
        ];
    }

    /**
     * The validated mappings, with every key present.
     *
     * @return list<array{bank_account_id: int, action: string, account_id: int|null, name: string|null, type: string|null}>
     */
    public function mappings(): array
    {
        /** @var array<int, array<string, mixed>> $mappings */
        $mappings = $this->validated('mappings');

        return array_values(array_map(fn (array $mapping) => [
            'bank_account_id' => (int) $mapping['bank_account_id'],
            'action' => (string) $mapping['action'],
            'account_id' => isset($mapping['account_id']) ? (int) $mapping['account_id'] : null,
            'name' => isset($mapping['name']) ? (string) $mapping['name'] : null,
            'type' => isset($mapping['type']) ? (string) $mapping['type'] : null,
        ], $mappings));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'mappings.*.account_id' => __('account'),
            'mappings.*.name' => __('account name'),
            'mappings.*.type' => __('account type'),
        ];
    }
}
