<?php

namespace App\Budget;

final readonly class GroupTotals
{
    public function __construct(
        public int $groupId,
        public int $assigned = 0,
        public int $activity = 0,
        public int $available = 0,
    ) {}

    public function add(CategoryMonth $category): self
    {
        return new self(
            $this->groupId,
            $this->assigned + $category->assigned,
            $this->activity + $category->activity,
            $this->available + $category->available,
        );
    }
}
