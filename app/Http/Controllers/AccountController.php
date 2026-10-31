<?php

namespace App\Http\Controllers;

use App\Actions\Accounts\AdjustAccountBalance;
use App\Actions\Accounts\CreateAccount;
use App\Actions\Accounts\UpdateAccount;
use App\Enums\AccountType;
use App\Http\Requests\Accounts\AdjustAccountBalanceRequest;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Team;
use App\Support\TransactionRegister;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Household accounts. Route model binding only finds accounts of the current
 * household (BelongsToTeam global scope), so other households get a 404.
 */
class AccountController extends Controller
{
    /**
     * Every transaction of every account of the household.
     */
    public function index(Request $request, Team $current_team, TransactionRegister $register): Response
    {
        return Inertia::render('accounts/index', [
            'register' => $register->forAccount(null, $request->query('filter')),
            'pickers' => fn () => $register->pickers($current_team),
        ]);
    }

    /**
     * Show an account.
     */
    public function show(Request $request, Team $current_team, Account $account, UpdateAccount $updateAccount, TransactionRegister $register): Response
    {
        return Inertia::render('accounts/show', [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'typeLabel' => $account->type->label(),
                'onBudget' => $account->on_budget,
                'note' => $account->note,
                'closed' => $account->isClosed(),
                'startingDate' => $account->starting_date->toDateString(),
                'balance' => (int) $account->transactions()->sum('amount'),
                'canChangeBudgetStatus' => $updateAccount->canChangeBudgetStatus($account),
                'bankBalance' => BankAccount::query()->whereBelongsTo($account)->whereNotNull('last_balance')->value('last_balance'),
            ],
            'register' => $register->forAccount($account, $request->query('filter')),
            'pickers' => fn () => $register->pickers($current_team),
        ]);
    }

    /**
     * Open an account with its current balance.
     */
    public function store(StoreAccountRequest $request, CreateAccount $createAccount): RedirectResponse
    {
        $account = $createAccount->handle(
            $request->user(),
            $request->validated('name'),
            AccountType::from($request->validated('type')),
            $request->boolean('on_budget'),
            (int) $request->validated('balance'),
            Carbon::parse($request->validated('starting_date')),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account “:name” added.', ['name' => $account->name])]);

        // The onboarding adds accounts one after the other without leaving the page.
        if ($request->boolean('stay')) {
            return back();
        }

        return to_route('accounts.show', ['account' => $account]);
    }

    /**
     * Update an account's name, note and budget status.
     */
    public function update(UpdateAccountRequest $request, Team $current_team, Account $account, UpdateAccount $updateAccount): RedirectResponse
    {
        $updateAccount->handle(
            $account,
            $request->validated('name'),
            $request->validated('note'),
            $request->boolean('on_budget'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account updated.')]);

        return back();
    }

    /**
     * Close an account: it moves to the "Fermés" section.
     */
    public function close(Team $current_team, Account $account): RedirectResponse
    {
        $account->update(['closed_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account closed.')]);

        return back();
    }

    /**
     * Reopen a closed account.
     */
    public function reopen(Team $current_team, Account $account): RedirectResponse
    {
        $account->update(['closed_at' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account reopened.')]);

        return back();
    }

    /**
     * Set the current balance of an off-budget account with an adjustment transaction.
     */
    public function adjustBalance(
        AdjustAccountBalanceRequest $request,
        Team $current_team,
        Account $account,
        AdjustAccountBalance $adjustBalance,
    ): RedirectResponse {
        $adjustBalance->handle($request->user(), $account, (int) $request->validated('balance'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Balance updated.')]);

        return back();
    }
}
