<?php

namespace App\Actions\Budget;

use App\Enums\MoneyMoveKind;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\TargetSnooze;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undo or redo a change of the budget screen: put back assigned amounts and
 * paused targets of a month, all or nothing, only if each still has the value
 * the change left. Otherwise someone (or a sync) changed it since.
 */
class RestoreBudget
{
    public function __construct(private AssignToCategory $assign) {}

    /**
     * @param  list<array{category_id: int, from: int, to: int}>  $assignments
     * @param  list<array{category_id: int, from: bool, to: bool}>  $snoozes
     *
     * @throws ValidationException When a value changed since.
     */
    public function handle(User $by, CarbonImmutable $month, MoneyMoveKind $kind, array $assignments, array $snoozes): void
    {
        DB::transaction(function () use ($by, $month, $kind, $assignments, $snoozes) {
            $day = $month->toDateString();

            $current = Assignment::withoutGlobalScopes()
                ->where('month', $day)
                ->whereIn('category_id', array_column($assignments, 'category_id'))
                ->lockForUpdate()
                ->pluck('amount', 'category_id');

            $paused = TargetSnooze::query()
                ->where('month', $day)
                ->whereIn('category_id', array_column($snoozes, 'category_id'))
                ->lockForUpdate()
                ->pluck('category_id')
                ->flip();

            foreach ($assignments as $change) {
                if ((int) ($current[$change['category_id']] ?? 0) !== $change['from']) {
                    throw $this->changedSince();
                }
            }

            foreach ($snoozes as $change) {
                if ($paused->has($change['category_id']) !== $change['from']) {
                    throw $this->changedSince();
                }
            }

            $categories = Category::query()->whereKey(array_column($assignments, 'category_id'))->get()->keyBy('id');

            foreach ($assignments as $change) {
                $this->assign->handle($by, $categories[$change['category_id']], $month, $change['to'], $kind);
            }

            foreach ($snoozes as $change) {
                if ($change['to']) {
                    TargetSnooze::firstOrCreate(['category_id' => $change['category_id'], 'month' => $day]);
                } else {
                    TargetSnooze::query()->where('category_id', $change['category_id'])->where('month', $day)->delete();
                }
            }
        });
    }

    private function changedSince(): ValidationException
    {
        return ValidationException::withMessages([
            'restore' => __('This changed since, nothing was undone.'),
        ]);
    }
}
