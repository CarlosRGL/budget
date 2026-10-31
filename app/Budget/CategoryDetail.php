<?php

namespace App\Budget;

/**
 * Figures of the category detail panel. Spent amounts are positive when money went out.
 */
final readonly class CategoryDetail
{
    public function __construct(
        public CategoryMonth $month,
        public int $assignedLastMonth,
        public int $spentLastMonth,
        public int $averageAssigned,
        public int $averageSpent,
    ) {}
}
