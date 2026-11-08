<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\ConfigurePayee;
use App\Enums\PayeeCategoryMode;
use App\Models\Payee;
use App\Models\Team;
use App\Support\TransactionRegister;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The household's payees: rename them, give them a default category, merge
 * duplicates and delete the ones no longer used.
 */
class PayeeController extends Controller
{
    public function index(Team $current_team, TransactionRegister $register): Response
    {
        return Inertia::render('payees/index', [
            'payees' => Payee::query()
                ->whereNull('transfer_account_id')
                ->with('renameRules')
                ->withCount('transactions')
                ->withMax('transactions', 'date')
                ->orderBy('name')
                ->get()
                ->map(fn (Payee $payee) => [
                    'id' => $payee->id,
                    'name' => $payee->name,
                    'categoryId' => $payee->last_category_id,
                    'categoryMode' => $payee->category_mode->value,
                    'transactions' => $payee->transactions_count,
                    'rules' => array_values($payee->renameRules->sortBy('contains')->pluck('contains')->all()),
                    'lastUsed' => $payee->getAttribute('transactions_max_date'),
                ])
                ->all(),
            'pickers' => $register->pickers($current_team),
        ]);
    }

    /**
     * Rename a payee, set how it categorizes and the bank labels it takes over.
     * A name already taken merges this payee into the one that has it.
     */
    public function update(Request $request, Team $current_team, Payee $payee, ConfigurePayee $configure): RedirectResponse
    {
        abort_if($payee->isTransfer(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('team_id', $current_team->id)],
            'category_mode' => ['sometimes', Rule::enum(PayeeCategoryMode::class)],
            'rules' => ['sometimes', 'array', 'max:50'],
            'rules.*' => ['required', 'string', 'min:2', 'max:255'],
            'apply_to_existing' => ['sometimes', 'boolean'],
        ], attributes: ['name' => __('name'), 'category_id' => __('category'), 'rules.*' => __('label text')]);

        $name = trim($validated['name']);
        $existing = Payee::query()->where('name', $name)->whereKeyNot($payee->id)->first();

        if ($existing?->isTransfer()) {
            return back()->withErrors(['name' => __('This name is reserved for a transfer.')]);
        }

        $kept = DB::transaction(function () use ($validated, $payee, $existing, $name, $configure) {
            if ($existing) {
                $existing->update(['last_category_id' => $validated['category_id'] ?? $existing->last_category_id]);
                $configure->merge($payee, $existing);
                $kept = $existing;
            } else {
                $payee->update(['name' => $name, 'last_category_id' => $validated['category_id'] ?? null]);
                $kept = $payee;
            }

            if (isset($validated['category_mode'])) {
                $kept->update(['category_mode' => $validated['category_mode']]);
            }

            if (isset($validated['rules'])) {
                $configure->syncRules($kept, $validated['rules']);
            }

            return $kept;
        });

        $changed = ($validated['apply_to_existing'] ?? false) ? $configure->applyToExisting($kept) : 0;

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $existing !== null => __('Merged with “:name”.', ['name' => $kept->name]),
            $changed > 0 => trans_choice('Payee updated, :count transaction changed.|Payee updated, :count transactions changed.', $changed),
            default => __('Payee updated.'),
        }]);

        return back();
    }

    /**
     * Fold several payees into one: their transactions and rules move to it.
     */
    public function mergeMany(Request $request, Team $current_team, ConfigurePayee $configure): RedirectResponse
    {
        $inHousehold = Rule::exists('payees', 'id')->where('team_id', $current_team->id)->whereNull('transfer_account_id');

        $validated = $request->validate([
            'into' => ['required', 'integer', $inHousehold],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', $inHousehold],
        ]);

        $into = Payee::query()->whereKey((int) $validated['into'])->firstOrFail();
        $sources = Payee::query()->whereKey($validated['ids'])->whereKeyNot($into->id)->get();

        foreach ($sources as $source) {
            $configure->merge($source, $into);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($sources->count()) {
            0 => __('Nothing to merge.'),
            default => trans_choice(':count payee merged into “:name”.|:count payees merged into “:name”.', $sources->count(), ['name' => $into->name]),
        }]);

        return back();
    }

    /**
     * Delete a payee: its transactions keep their amounts but lose the payee.
     */
    public function destroy(Team $current_team, Payee $payee): RedirectResponse
    {
        abort_if($payee->isTransfer(), 404);

        $payee->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payee deleted.')]);

        return back();
    }
}
