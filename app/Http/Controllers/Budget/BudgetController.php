<?php

namespace App\Http\Controllers\Budget;

use App\Budget\BudgetCalculator;
use App\Budget\MonthBudget;
use App\Budget\TargetCalculator;
use App\Budget\TargetProgress;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\MoneyMove;
use App\Models\MonthNote;
use App\Models\Target;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\BudgetMonth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The budget screen of one month (design §5.5). Every figure comes from the
 * BudgetCalculator, computed once per request and only for the props asked for.
 */
class BudgetController extends Controller
{
    public function show(
        Request $request,
        Team $current_team,
        BudgetCalculator $calculator,
        TargetCalculator $targetCalculator,
        ?string $month = null,
    ): Response {
        $month = $month === null ? CarbonImmutable::now()->startOfMonth() : BudgetMonth::fromUrl($month);

        $budget = null;
        $progress = null;
        $monthBudget = function () use (&$budget, $calculator, $current_team, $month): MonthBudget {
            return $budget ??= $calculator->forMonth($current_team, $month);
        };
        $targetProgress = function () use (&$progress, $targetCalculator, $monthBudget): array {
            return $progress ??= $targetCalculator->forMonth($monthBudget());
        };

        return Inertia::render('budget/show', [
            'month' => BudgetMonth::toUrl($month),
            'previousMonth' => BudgetMonth::toUrl($month->subMonth()),
            'nextMonth' => BudgetMonth::toUrl($month->addMonth()),
            'readyToAssign' => fn () => $monthBudget()->readyToAssign,
            'uncategorizedActivity' => fn () => $monthBudget()->uncategorizedActivity,
            'overspentCount' => fn () => $monthBudget()->overspentCount(),
            'underfundedCount' => fn () => count(array_filter(
                $targetProgress(),
                fn (TargetProgress $target) => $target->isUnderfunded(),
            )),
            'note' => fn () => MonthNote::where('month', $month->toDateString())->value('note'),
            'groups' => fn () => $this->groups($monthBudget(), $targetProgress()),
            'moves' => fn () => $this->moves($month),
            'detail' => Inertia::optional(fn () => $this->detail($request, $calculator, $current_team, $month)),
            'pendingInvitations' => fn () => $this->pendingInvitations($request->user()),
        ]);
    }

    /**
     * @param  array<int, TargetProgress>  $progress
     * @return array<int, array<string, mixed>>
     */
    private function groups(MonthBudget $budget, array $progress): array
    {
        $targets = Target::all()->keyBy('category_id');

        return CategoryGroup::with('categories')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (CategoryGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'hidden' => $group->hidden,
                'assigned' => $budget->group($group)->assigned,
                'activity' => $budget->group($group)->activity,
                'available' => $budget->group($group)->available,
                'categories' => $group->categories->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'hidden' => $category->hidden,
                    'note' => $category->note,
                    'carryover' => $budget->category($category)->carryover,
                    'assigned' => $budget->category($category)->assigned,
                    'activity' => $budget->category($category)->activity,
                    'available' => $budget->category($category)->available,
                    'target' => $this->target($targets->get($category->id), $progress[$category->id] ?? null),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function target(?Target $target, ?TargetProgress $progress): ?array
    {
        if ($target === null || $progress === null) {
            return null;
        }

        return [
            'type' => $target->type->value,
            'amount' => $target->amount,
            'weekday' => $target->weekday,
            'dueDate' => $target->due_date?->toDateString(),
            'repeat' => $target->repeat,
            'needed' => $progress->needed,
            'funded' => $progress->funded,
            'toGo' => $progress->toGo,
            'ratio' => $progress->ratio,
            'snoozed' => $progress->snoozed,
            'underfunded' => $progress->isUnderfunded(),
            'overfunded' => $progress->isOverfunded(),
        ];
    }

    /**
     * Money assigned or moved this month, newest first. A null end is Ready to Assign.
     *
     * @return array<int, array<string, mixed>>
     */
    private function moves(CarbonImmutable $month): array
    {
        return MoneyMove::with(['fromCategory:id,name', 'toCategory:id,name', 'user:id,name'])
            ->where('month', $month->toDateString())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MoneyMove $move) => [
                'id' => $move->id,
                'kind' => $move->kind->value,
                'amount' => $move->amount,
                'from' => $move->fromCategory ? ['id' => $move->fromCategory->id, 'name' => $move->fromCategory->name] : null,
                'to' => $move->toCategory ? ['id' => $move->toCategory->id, 'name' => $move->toCategory->name] : null,
                'user' => $move->user?->name,
                'at' => $move->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Figures of the detail panel for the `?category=` of a partial reload.
     *
     * @return array<string, int>|null
     */
    private function detail(Request $request, BudgetCalculator $calculator, Team $team, CarbonImmutable $month): ?array
    {
        if (! $request->filled('category')) {
            return null;
        }

        $category = Category::findOrFail($request->integer('category'));
        $detail = $calculator->categoryDetail($team, $category, $month);

        return [
            'categoryId' => $category->id,
            'carryover' => $detail->month->carryover,
            'assigned' => $detail->month->assigned,
            'activity' => $detail->month->activity,
            'available' => $detail->month->available,
            'assignedLastMonth' => $detail->assignedLastMonth,
            'spentLastMonth' => $detail->spentLastMonth,
            'averageAssigned' => $detail->averageAssigned,
            'averageSpent' => $detail->averageSpent,
        ];
    }

    /**
     * Invitations to other households waiting for the member's answer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pendingInvitations(User $user): array
    {
        return TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ])
            ->values()
            ->all();
    }
}
