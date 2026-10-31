<?php

namespace App\Budget;

use App\Models\Category;
use App\Models\CategoryGroup;
use Carbon\CarbonImmutable;

/**
 * Every figure of the budget screen for one month.
 */
final readonly class MonthBudget
{
    /**
     * @param  array<int, CategoryMonth>  $categories  Keyed by category id.
     * @param  array<int, GroupTotals>  $groups  Keyed by group id.
     */
    public function __construct(
        public int $teamId,
        public CarbonImmutable $month,
        public int $readyToAssign,
        public int $uncategorizedActivity,
        public array $categories,
        public array $groups,
    ) {}

    public function withReadyToAssign(int $readyToAssign): self
    {
        return new self($this->teamId, $this->month, $readyToAssign, $this->uncategorizedActivity, $this->categories, $this->groups);
    }

    public function category(Category|int $category): CategoryMonth
    {
        $id = $category instanceof Category ? $category->id : $category;

        return $this->categories[$id]
            ?? CategoryMonth::empty($id, $category instanceof Category ? $category->category_group_id : 0);
    }

    public function group(CategoryGroup|int $group): GroupTotals
    {
        $id = $group instanceof CategoryGroup ? $group->id : $group;

        return $this->groups[$id] ?? new GroupTotals($id);
    }

    /**
     * Number of categories with a negative available amount.
     */
    public function overspentCount(): int
    {
        return count(array_filter($this->categories, fn (CategoryMonth $category) => $category->isOverspent()));
    }
}
