<?php

namespace App\Http\Requests\Budget;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a category (name required) or update some of its fields.
 */
class SaveCategoryRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [...($creating ? [] : ['sometimes']), 'required', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'hidden' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => __('name'), 'note' => __('note'), 'hidden' => __('hidden')];
    }
}
