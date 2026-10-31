<?php

namespace App\Http\Requests\Budget;

use App\Enums\AutoAssignStrategy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutoAssignRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'strategy' => ['required', Rule::enum(AutoAssignStrategy::class)],
            'category_ids' => ['nullable', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('team_id', $this->user()?->current_team_id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['strategy' => __('assignment mode'), 'category_ids' => __('categories')];
    }

    /**
     * The selected categories, or null for the whole month.
     *
     * @return list<int>|null
     */
    public function categoryIds(): ?array
    {
        $ids = $this->validated('category_ids');

        return is_array($ids) ? array_values(array_map(intval(...), $ids)) : null;
    }
}
