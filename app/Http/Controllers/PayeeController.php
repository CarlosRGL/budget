<?php

namespace App\Http\Controllers;

use App\Enums\PayeeCategoryMode;
use App\Models\Payee;
use App\Models\PayeeRenameRule;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\TransactionSplit;
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
    public function update(Request $request, Team $current_team, Payee $payee): RedirectResponse
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

        $kept = DB::transaction(function () use ($validated, $payee, $existing, $name) {
            if ($existing) {
                $existing->update(['last_category_id' => $validated['category_id'] ?? $existing->last_category_id]);
                $this->merge($payee, $existing);
                $kept = $existing;
            } else {
                $payee->update(['name' => $name, 'last_category_id' => $validated['category_id'] ?? null]);
                $kept = $payee;
            }

            if (isset($validated['category_mode'])) {
                $kept->update(['category_mode' => $validated['category_mode']]);
            }

            if (isset($validated['rules'])) {
                $this->syncRules($kept, $validated['rules']);
            }

            return $kept;
        });

        $changed = ($validated['apply_to_existing'] ?? false) ? $this->applyToExisting($kept) : 0;

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
    public function mergeMany(Request $request, Team $current_team): RedirectResponse
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
            $this->merge($source, $into);
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

    /**
     * Give the payee exactly these rename rules. A text already used by another
     * payee's rule moves to this one.
     *
     * @param  list<string>  $texts
     */
    private function syncRules(Payee $payee, array $texts): void
    {
        $texts = array_values(array_unique(array_map('trim', $texts)));

        PayeeRenameRule::query()->where('payee_id', $payee->id)->whereNotIn('contains', $texts)->delete();

        foreach ($texts as $text) {
            PayeeRenameRule::updateOrCreate(
                ['team_id' => $payee->team_id, 'contains' => $text],
                ['payee_id' => $payee->id],
            );
        }
    }

    /**
     * Run the payee's rules on the transactions already imported, then give
     * its default category to its transactions still without one. Payees the
     * rules emptied are deleted.
     *
     * @return int How many transactions changed.
     */
    private function applyToExisting(Payee $payee): int
    {
        $previous = Transaction::query()
            ->whereNotNull('raw_label')
            ->where('payee_id', '!=', $payee->id)
            ->pluck('payee_id', 'id');

        $changed = 0;

        foreach ($payee->renameRules()->get() as $rule) {
            $changed += $rule->applyToExisting();
        }

        $categoryId = $payee->refresh()->defaultCategoryId();

        if ($categoryId !== null) {
            $changed += Transaction::query()
                ->where('payee_id', $payee->id)
                ->whereNull('category_id')
                ->whereNull('transfer_pair_id')
                ->where('inflow_to_rta', false)
                ->whereDoesntHave('splits')
                ->whereHas('account', fn ($query) => $query->where('on_budget', true))
                ->update(['category_id' => $categoryId]);
        }

        Payee::query()
            ->whereKey($previous->filter()->unique()->values())
            ->whereKeyNot($payee->id)
            ->whereNull('transfer_account_id')
            ->doesntHave('transactions')
            ->doesntHave('renameRules')
            ->get()
            ->each(fn (Payee $emptied) => $emptied->delete());

        return $changed;
    }

    private function merge(Payee $source, Payee $into): void
    {
        DB::transaction(function () use ($source, $into) {
            Transaction::query()->where('payee_id', $source->id)->update(['payee_id' => $into->id]);
            TransactionSplit::query()->where('payee_id', $source->id)->update(['payee_id' => $into->id]);
            PayeeRenameRule::query()->where('payee_id', $source->id)->update(['payee_id' => $into->id]);

            if ($into->last_category_id === null && $source->last_category_id !== null) {
                $into->update(['last_category_id' => $source->last_category_id]);
            }

            $source->delete();
        });
    }
}
