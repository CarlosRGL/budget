<?php

namespace App\Budget;

/**
 * How far a category is from its target this month, in cents.
 */
final readonly class TargetProgress
{
    /**
     * @param  int  $needed  What the target asks to assign this month.
     * @param  int  $funded  What has been assigned this month.
     * @param  float  $ratio  Progress between 0 and 1, for the progress ring.
     */
    public function __construct(
        public int $categoryId,
        public int $needed,
        public int $funded,
        public int $toGo,
        public float $ratio,
        public bool $snoozed,
    ) {}

    public function isUnderfunded(): bool
    {
        return $this->toGo > 0;
    }

    public function isOverfunded(): bool
    {
        return ! $this->snoozed && $this->funded > $this->needed;
    }
}
