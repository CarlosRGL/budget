<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The starter categories the member kept (and maybe renamed or added) during
 * the onboarding, grouped like the budget.
 */
class StoreStarterCategoriesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'groups' => ['required', 'array', 'min:1', 'max:20'],
            'groups.*.name' => ['required', 'string', 'max:255'],
            'groups.*.categories' => ['required', 'array', 'min:1', 'max:50'],
            'groups.*.categories.*' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'groups' => __('categories'),
            'groups.*.name' => __('group name'),
            'groups.*.categories' => __('categories'),
            'groups.*.categories.*' => __('category name'),
        ];
    }
}
