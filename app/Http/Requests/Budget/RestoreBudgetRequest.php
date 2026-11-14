<?php

namespace App\Http\Requests\Budget;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An undo or redo step of the budget screen: for each category, the value it
 * should still have (`from`) and the one to put back (`to`).
 */
class RestoreBudgetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $householdCategory = Rule::exists('categories', 'id')->where('team_id', $this->user()?->current_team_id);
        $cents = ['required', 'integer', 'between:-100000000000,100000000000'];

        return [
            'kind' => ['required', Rule::in(['undo', 'redo'])],
            'assignments' => ['array', 'max:500'],
            'assignments.*.category_id' => ['required', 'integer', 'distinct', $householdCategory],
            'assignments.*.from' => $cents,
            'assignments.*.to' => $cents,
            'snoozes' => ['array', 'max:500'],
            'snoozes.*.category_id' => ['required', 'integer', 'distinct', $householdCategory],
            'snoozes.*.from' => ['required', 'boolean'],
            'snoozes.*.to' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<array{category_id: int, from: int, to: int}>
     */
    public function assignments(): array
    {
        return array_map(fn (array $change) => [
            'category_id' => (int) $change['category_id'],
            'from' => (int) $change['from'],
            'to' => (int) $change['to'],
        ], array_values($this->validated('assignments', [])));
    }

    /**
     * @return list<array{category_id: int, from: bool, to: bool}>
     */
    public function snoozes(): array
    {
        return array_map(fn (array $change) => [
            'category_id' => (int) $change['category_id'],
            'from' => filter_var($change['from'], FILTER_VALIDATE_BOOLEAN),
            'to' => filter_var($change['to'], FILTER_VALIDATE_BOOLEAN),
        ], array_values($this->validated('snoozes', [])));
    }
}
