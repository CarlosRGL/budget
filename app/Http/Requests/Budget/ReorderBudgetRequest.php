<?php

namespace App\Http\Requests\Budget;

use App\Models\Category;
use App\Models\CategoryGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The full ordered structure of the budget: every group of the household once,
 * each with its categories in order.
 */
class ReorderBudgetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'groups' => ['required', 'array'],
            'groups.*' => ['required', 'array:group_id,category_ids'],
            'groups.*.group_id' => ['required', 'integer', 'distinct'],
            'groups.*.category_ids' => ['present', 'array'],
            'groups.*.category_ids.*' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var list<array{group_id: int, category_ids: list<int>}> $groups */
                $groups = $this->input('groups');
                $groupIds = array_map(fn (array $group) => (int) $group['group_id'], $groups);
                $categoryIds = array_map('intval', array_merge(...array_column($groups, 'category_ids')));

                $sameIds = fn (array $sent, array $known) => count($sent) === count(array_unique($sent))
                    && array_diff($sent, $known) === []
                    && array_diff($known, $sent) === [];

                if (! $sameIds($groupIds, CategoryGroup::pluck('id')->all())
                    || ! $sameIds($categoryIds, Category::pluck('id')->all())) {
                    $validator->errors()->add('groups', __('The order sent does not match the household\'s groups and categories: reload the page.'));
                }
            },
        ];
    }
}
