<?php

namespace App\Http\Requests\Transactions;

use App\Enums\PayeeCategoryMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

class SaveTransactionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', $this->inHousehold('accounts')],
            'date' => ['required', 'date'],
            'payee_id' => ['nullable', 'integer', $this->inHousehold('payees')],
            'payee_name' => ['nullable', 'string', 'max:255'],
            'transfer_account_id' => ['nullable', 'integer', 'different:account_id', $this->inHousehold('accounts')],
            'category_id' => ['nullable', 'integer', $this->inHousehold('categories')],
            'inflow_to_rta' => ['boolean'],
            'memo' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'between:-100000000000,100000000000'],
            'cleared' => ['boolean'],
            'splits' => ['nullable', 'array'],
            'splits.*.category_id' => ['nullable', 'integer', $this->inHousehold('categories')],
            'splits.*.inflow_to_rta' => ['boolean'],
            'splits.*.memo' => ['nullable', 'string', 'max:255'],
            'splits.*.amount' => ['required', 'integer', 'between:-100000000000,100000000000'],
            'payee_settings' => ['nullable', 'array'],
            'payee_settings.category_mode' => ['required_with:payee_settings', Rule::enum(PayeeCategoryMode::class)],
            'payee_settings.category_id' => ['nullable', 'integer', $this->inHousehold('categories')],
            'payee_settings.rules' => ['array', 'max:50'],
            'payee_settings.rules.*' => ['required', 'string', 'min:2', 'max:255'],
            'payee_settings.apply_to_existing' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $splits = $this->input('splits') ?? [];

                if ($this->filled('transfer_account_id') || $splits === [] || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if (count($splits) < 2) {
                    $validator->errors()->add('splits', __('A split needs at least two lines.'));
                }

                if (array_sum(array_column($splits, 'amount')) !== (int) $this->input('amount')) {
                    $validator->errors()->add('splits', __('The split lines must add up to exactly the transaction\'s amount.'));
                }

                foreach ($splits as $index => $split) {
                    if (empty($split['category_id']) && empty($split['inflow_to_rta'])) {
                        $validator->errors()->add("splits.{$index}.category_id", __('Each split line needs a category.'));
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'account_id' => __('account'),
            'date' => __('date'),
            'payee_id' => __('payee'),
            'payee_name' => __('payee'),
            'transfer_account_id' => __('transfer account'),
            'category_id' => __('category'),
            'memo' => __('memo'),
            'amount' => __('amount'),
            'payee_settings.category_id' => __('category'),
            'payee_settings.rules.*' => __('label text'),
        ];
    }

    /**
     * The validated transaction, typed for SaveTransaction.
     *
     * @return array{
     *     account_id: int,
     *     date: string,
     *     payee_id: int|null,
     *     payee_name: string|null,
     *     transfer_account_id: int|null,
     *     category_id: int|null,
     *     inflow_to_rta: bool,
     *     memo: string|null,
     *     amount: int,
     *     cleared: bool,
     *     splits: list<array{category_id: int|null, inflow_to_rta: bool, memo: string|null, amount: int}>,
     * }
     */
    public function transaction(): array
    {
        $validated = $this->safe();
        $optionalInt = fn (mixed $value): ?int => $value === null ? null : (int) $value;
        $optionalString = fn (mixed $value): ?string => $value === null ? null : (string) $value;
        /** @var array<int, array<string, mixed>> $splits */
        $splits = $validated['splits'] ?? [];

        return [
            'account_id' => $this->integer('account_id'),
            'date' => $this->string('date')->value(),
            'payee_id' => $optionalInt($validated['payee_id'] ?? null),
            'payee_name' => $optionalString($validated['payee_name'] ?? null),
            'transfer_account_id' => $optionalInt($validated['transfer_account_id'] ?? null),
            'category_id' => $optionalInt($validated['category_id'] ?? null),
            'inflow_to_rta' => $this->boolean('inflow_to_rta'),
            'memo' => $optionalString($validated['memo'] ?? null),
            'amount' => $this->integer('amount'),
            'cleared' => $this->boolean('cleared'),
            'splits' => array_values(array_map(fn (array $split) => [
                'category_id' => $optionalInt($split['category_id'] ?? null),
                'inflow_to_rta' => (bool) ($split['inflow_to_rta'] ?? false),
                'memo' => $optionalString($split['memo'] ?? null),
                'amount' => (int) $split['amount'],
            ], $splits)),
        ];
    }

    /**
     * How the payee of the transaction should categorize and which bank labels
     * it takes over, when the member changed them while saving.
     *
     * @return array{category_mode: PayeeCategoryMode, category_id: int|null, rules: list<string>, apply_to_existing: bool}|null
     */
    public function payeeSettings(): ?array
    {
        if (! $this->filled('payee_settings')) {
            return null;
        }

        /** @var array<int, string> $rules */
        $rules = $this->safe()['payee_settings']['rules'] ?? [];

        return [
            'category_mode' => $this->enum('payee_settings.category_mode', PayeeCategoryMode::class) ?? PayeeCategoryMode::History,
            'category_id' => $this->filled('payee_settings.category_id') ? $this->integer('payee_settings.category_id') : null,
            'rules' => array_values(array_map('strval', $rules)),
            'apply_to_existing' => $this->boolean('payee_settings.apply_to_existing'),
        ];
    }

    private function inHousehold(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('team_id', $this->user()?->current_team_id);
    }
}
