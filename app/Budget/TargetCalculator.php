<?php

namespace App\Budget;

use App\Enums\TargetType;
use App\Models\Target;
use App\Models\TargetSnooze;
use Carbon\CarbonImmutable;

/**
 * What each target asks to assign in a month (design §5.6).
 */
class TargetCalculator
{
    /**
     * @return array<int, TargetProgress> Keyed by category id, only categories with a target.
     */
    public function forMonth(MonthBudget $budget): array
    {
        $targets = Target::withoutGlobalScopes()->where('team_id', $budget->teamId)->get();

        $snoozed = TargetSnooze::query()
            ->whereIn('category_id', $targets->pluck('category_id'))
            ->where('month', $budget->month->toDateString())
            ->pluck('category_id')
            ->flip();

        return $targets
            ->mapWithKeys(fn (Target $target) => [
                $target->category_id => $this->progress(
                    $target,
                    $budget->category($target->category_id),
                    $budget->month,
                    $snoozed->has($target->category_id),
                ),
            ])
            ->all();
    }

    private function progress(Target $target, CategoryMonth $category, CarbonImmutable $month, bool $snoozed): TargetProgress
    {
        $needed = $snoozed ? 0 : $this->needed($target, $category, $month);
        $funded = $category->assigned;

        $ratio = $target->type === TargetType::ByDate
            ? min(1, max(0, $category->available) / max(1, $target->amount))
            : ($needed === 0 ? 1.0 : min(1, max(0, $funded) / $needed));

        return new TargetProgress(
            categoryId: $target->category_id,
            needed: $needed,
            funded: $funded,
            toGo: max(0, $needed - $funded),
            ratio: (float) $ratio,
            snoozed: $snoozed,
        );
    }

    private function needed(Target $target, CategoryMonth $category, CarbonImmutable $month): int
    {
        return match ($target->type) {
            TargetType::RefillUpTo => max(0, $target->amount - $category->carryover),
            TargetType::SetAside => $target->amount,
            TargetType::Weekly => $target->amount * $this->countWeekdays($month, $target->weekday ?? 1),
            TargetType::ByDate => $this->neededByDate($target, $category, $month),
        };
    }

    /**
     * Spread what is still missing over the months left until the due date, rounding up
     * so the target is reached in time.
     */
    private function neededByDate(Target $target, CategoryMonth $category, CarbonImmutable $month): int
    {
        $missing = max(0, $target->amount - $category->carryover);
        $due = $this->nextDueMonth($target, $month);

        if ($due === null || $due->lessThanOrEqualTo($month)) {
            return $missing;
        }

        $monthsLeft = (int) $month->diffInMonths($due) + 1;

        return intdiv($missing + $monthsLeft - 1, $monthsLeft);
    }

    private function nextDueMonth(Target $target, CarbonImmutable $month): ?CarbonImmutable
    {
        if ($target->due_date === null) {
            return null;
        }

        $due = CarbonImmutable::parse($target->due_date)->startOfMonth();

        while ($target->repeat === 'yearly' && $due->lessThan($month)) {
            $due = $due->addYear();
        }

        return $due;
    }

    /**
     * @param  int  $weekday  ISO weekday, 1 = Monday.
     */
    private function countWeekdays(CarbonImmutable $month, int $weekday): int
    {
        $count = 0;
        for ($day = $month->startOfMonth(); $day->month === $month->month; $day = $day->addDay()) {
            $count += (int) ($day->dayOfWeekIso === $weekday);
        }

        return $count;
    }
}
