<?php

namespace App\Http\Requests\Budget;

use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteCategoryRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Category $category */
        $category = $this->route('category');

        return [
            'replacement_category_id' => [
                'required',
                'integer',
                Rule::notIn([$category->id]),
                Rule::exists('categories', 'id')->where('team_id', $this->user()?->current_team_id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'replacement_category_id.not_in' => __('Choose another category to take over the transactions.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['replacement_category_id' => __('replacement category')];
    }
}
