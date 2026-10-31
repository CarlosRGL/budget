<?php

namespace App\Http\Requests\Budget;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A null category stands for Ready to Assign.
 */
class MoveMoneyRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $householdCategory = Rule::exists('categories', 'id')->where('team_id', $this->user()?->current_team_id);

        return [
            'from_category_id' => ['nullable', 'integer', $householdCategory],
            'to_category_id' => ['nullable', 'required_without:from_category_id', 'integer', 'different:from_category_id', $householdCategory],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_category_id.required_without' => __('Choose a source or destination category.'),
            'to_category_id.different' => __('Choose another destination category.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from_category_id' => __('source category'),
            'to_category_id' => __('destination category'),
            'amount' => __('amount'),
        ];
    }
}
