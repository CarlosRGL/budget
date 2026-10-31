<?php

namespace App\Actions\Budget;

use App\Budget\BudgetCalculator;
use App\Budget\CategoryDetail;
use App\Budget\TargetCalculator;
use App\Enums\AutoAssignStrategy;
use App\Enums\MoneyMoveKind;
use App\Models\Category;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class AutoAssign
{
    public function __construct(
        private BudgetCalculator $budget,
        private TargetCalculator $targets,
        private AssignToCategory $assign,
    ) {}

    /**
     * Assign a whole month at once, logging one money move per changed category.
     *
     * With `$only`, just those categories are touched (the budget's selection).
     *
     * @param  list<int>|null  $only
     * @return array{amount: int, categories: int} Net amount taken from Ready to Assign
     *                                             and number of categories changed.
     */
    public function handle(User $by, Team $team, CarbonImmutable $month, AutoAssignStrategy $strategy, ?array $only = null): array
    {
        return DB::transaction(function () use ($by, $team, $month, $strategy, $only) {
            $categories = $this->categories($team);

            if ($only !== null) {
                $categories = $categories->filter(fn (Category $category) => in_array($category->id, $only, true))->values();
            }

            $amounts = match ($strategy) {
                AutoAssignStrategy::Underfunded => $this->underfunded($team, $month, $categories),
                AutoAssignStrategy::LastMonth => array_map(
                    fn (CategoryDetail $detail) => $detail->assignedLastMonth,
                    $this->budget->categoryDetails($team, $month),
                ),
                AutoAssignStrategy::Average => array_map(
                    fn (CategoryDetail $detail) => $detail->averageAssigned,
                    $this->budget->categoryDetails($team, $month),
                ),
                AutoAssignStrategy::Reset => array_fill_keys($categories->modelKeys(), 0),
            };

            $kind = $strategy === AutoAssignStrategy::Reset ? MoneyMoveKind::Reset : MoneyMoveKind::AutoAssign;
            $total = 0;
            $changed = 0;

            foreach ($categories as $category) {
                if (! array_key_exists($category->id, $amounts)) {
                    continue;
                }

                $delta = $this->assign->handle($by, $category, $month, $amounts[$category->id], $kind);
                $total += $delta;
                $changed += (int) ($delta !== 0);
            }

            return ['amount' => $total, 'categories' => $changed];
        });
    }

    /**
     * New assigned amounts of the visible categories whose target is not met,
     * in budget order, until Ready to Assign runs out.
     *
     * @param  EloquentCollection<int, Category>  $categories
     * @return array<int, int>
     */
    private function underfunded(Team $team, CarbonImmutable $month, EloquentCollection $categories): array
    {
        $budget = $this->budget->forMonth($team, $month);
        $progress = $this->targets->forMonth($budget);
        $readyToAssign = $budget->readyToAssign;
        $amounts = [];

        foreach ($categories as $category) {
            $target = $progress[$category->id] ?? null;

            if ($readyToAssign <= 0) {
                break;
            }

            if ($target === null || $target->snoozed || $target->toGo <= 0 || $category->hidden || $category->getAttribute('group_hidden')) {
                continue;
            }

            $funding = min($target->toGo, $readyToAssign);
            $amounts[$category->id] = $budget->category($category)->assigned + $funding;
            $readyToAssign -= $funding;
        }

        return $amounts;
    }

    /**
     * Categories of the household in budget order, with `group_hidden`.
     *
     * @return EloquentCollection<int, Category>
     */
    private function categories(Team $team): EloquentCollection
    {
        return Category::withoutGlobalScopes()
            ->join('category_groups', 'category_groups.id', '=', 'categories.category_group_id')
            ->where('categories.team_id', $team->id)
            ->orderBy('category_groups.sort')
            ->orderBy('category_groups.id')
            ->orderBy('categories.sort')
            ->orderBy('categories.id')
            ->select('categories.*', 'category_groups.hidden as group_hidden')
            ->get();
    }
}
