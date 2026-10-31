<?php

namespace App\Http\Controllers;

use App\Actions\Accounts\AdjustAccountBalance;
use App\Actions\Budget\SaveTarget;
use App\Enums\AccountType;
use App\Enums\TargetType;
use App\Http\Requests\Loans\SaveLoanRequest;
use App\Loans\LoanReport;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Loan;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Loans with their amortization: where they stand and how they go down.
 */
class LoanController extends Controller
{
    public function index(): Response
    {
        $today = CarbonImmutable::today();

        return Inertia::render('loans/index', [
            'loans' => Loan::query()->with(['parts', 'account', 'category'])->orderBy('name')->get()
                ->map(fn (Loan $loan) => LoanReport::summary($loan, $today))
                ->all(),
            'accounts' => $this->loanAccounts(),
            'categoryGroups' => $this->categoryGroups(),
        ]);
    }

    public function show(Team $current_team, Loan $loan): Response
    {
        $loan->load(['parts', 'account', 'category']);
        $today = CarbonImmutable::today();

        return Inertia::render('loans/show', [
            'loan' => LoanReport::summary($loan, $today),
            'months' => LoanReport::months($loan),
            'payments' => LoanReport::payments($loan, $today),
            'accounts' => $this->loanAccounts(),
            'categoryGroups' => $this->categoryGroups(),
        ]);
    }

    public function store(SaveLoanRequest $request, Team $current_team, SaveTarget $saveTarget): RedirectResponse
    {
        $loan = DB::transaction(function () use ($request, $saveTarget) {
            $loan = Loan::create([
                ...$request->safe()->only(['name', 'account_id', 'category_id']),
                // Due dates are posted from now on: the account is brought
                // to the remaining capital once, with "Mettre à jour".
                'posted_through' => $request->validated('account_id') ? CarbonImmutable::today() : null,
            ]);
            $loan->parts()->createMany($request->validated('parts'));
            $this->targetPayment($loan, $saveTarget);

            return $loan;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Loan added.')]);

        return to_route('loans.show', [$current_team, $loan]);
    }

    public function update(SaveLoanRequest $request, Team $current_team, Loan $loan, SaveTarget $saveTarget): RedirectResponse
    {
        DB::transaction(function () use ($request, $loan, $saveTarget) {
            $loan->fill($request->safe()->only(['name', 'account_id', 'category_id']));

            if ($loan->isDirty('account_id')) {
                $loan->posted_through = $loan->account_id ? Carbon::today() : null;
            }

            $loan->save();
            $loan->parts()->delete();
            $loan->parts()->createMany($request->validated('parts'));
            $this->targetPayment($loan, $saveTarget);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Loan updated.')]);

        return back();
    }

    public function destroy(Team $current_team, Loan $loan): RedirectResponse
    {
        $loan->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Loan deleted.')]);

        return to_route('loans.index', $current_team);
    }

    /**
     * Bring the linked loan account to minus the remaining capital.
     */
    public function syncAccount(Request $request, Team $current_team, Loan $loan, AdjustAccountBalance $adjust): RedirectResponse
    {
        $loan->load(['parts', 'account']);
        abort_if($loan->account === null, 422, __('No account is linked to this loan.'));

        $today = CarbonImmutable::today();
        $adjust->handle($request->user(), $loan->account, -LoanReport::remaining($loan, $today));
        $loan->update(['posted_through' => $today]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account “:name” updated.', ['name' => $loan->account->name])]);

        return back();
    }

    /**
     * The linked category's target follows the monthly payment.
     */
    private function targetPayment(Loan $loan, SaveTarget $saveTarget): void
    {
        $category = $loan->category()->first();
        $payment = LoanReport::payment($loan->load('parts'), CarbonImmutable::today());

        if ($category !== null && $payment > 0) {
            $saveTarget->handle($category, TargetType::RefillUpTo, $payment);
        }
    }

    /**
     * @return list<array{id: int, name: string, categories: list<array{id: int, name: string}>}>
     */
    private function categoryGroups(): array
    {
        return array_values(CategoryGroup::query()
            ->with('categories')
            ->orderBy('sort')
            ->get()
            ->map(fn (CategoryGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'categories' => array_values($group->categories
                    ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name])
                    ->all()),
            ])
            ->all());
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function loanAccounts(): array
    {
        return array_values(Account::query()
            ->whereNull('closed_at')
            ->where('type', AccountType::Loan)
            ->orderBy('sort')
            ->get(['id', 'name'])
            ->map(fn (Account $account) => ['id' => $account->id, 'name' => $account->name])
            ->all());
    }
}
