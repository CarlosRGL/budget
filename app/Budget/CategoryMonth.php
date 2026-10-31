<?php

namespace App\Budget;

/**
 * One category's figures for one budget month, in cents.
 */
final readonly class CategoryMonth
{
    /**
     * Money in the envelope at the end of the month: negative when overspent.
     */
    public int $available;

    public function __construct(
        public int $categoryId,
        public int $groupId,
        public int $carryover,
        public int $assigned,
        public int $activity,
    ) {
        $this->available = $carryover + $assigned + $activity;
    }

    public static function empty(int $categoryId, int $groupId): self
    {
        return new self($categoryId, $groupId, 0, 0, 0);
    }

    public function isOverspent(): bool
    {
        return $this->available < 0;
    }
}
